<?php

namespace App\Jobs;

use App\Models\DocumentAnalysis;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Filament\Notifications\Notification as FilamentNotification;

/**
 * Job orquestrador para análise de documentos de processo via map-reduce.
 *
 * Responsabilidades:
 * 1. Criar registro principal de análise
 * 2. Disparar jobs de DOWNLOAD em paralelo via Bus::batch()
 * 3. Após downloads, disparar jobs de MAP para processamento paralelo
 * 4. Coordenar callbacks de conclusão
 *
 * Arquitetura de Processamento Paralelo:
 * - DOWNLOAD: Bus::batch() com DownloadDocumentJob (paralelo)
 * - MAP: Bus::batch() com MapDocumentAnalysisJob (paralelo)
 * - REDUCE: Bus::batch() com ReduceBatchJob (paralelo por nível)
 */
class AnalyzeProcessDocuments implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout;
    public int $tries;
    public int $uniqueFor;

    public function __construct(
        public int $userId,
        public string $numeroProcesso,
        public array $documentos,
        public array $contextoDados,
        public string $promptTemplate,              // Prompt para parecer final (REDUCE)
        public string $aiProvider,
        public bool $deepThinkingEnabled,
        public string $userLogin,
        public string $senha,
        public int $judicialUserId,
        public string $analysisStrategy = 'evolutionary',
        public ?string $aiModelId = null,                 // Modelo para REDUCE (parecer final)
        public ?string $documentAnalysisPrompt = null, // Prompt customizado para análise de documentos (MAP)
        public ?string $chave = null,                  // Chave do processo (para processos sigilosos)
        public ?string $mapModelId = null              // Modelo para MAP (análise de documentos)
    ) {
        $this->timeout = config('analysis.jobs.analyze_process.timeout', 1800);
        $this->tries = config('analysis.jobs.analyze_process.tries', 2);
        $this->uniqueFor = config('analysis.jobs.analyze_process.unique_for', 600);
    }

    /**
     * Chave única para evitar duplicação
     */
    public function uniqueId(): string
    {
        return "analyze_process_{$this->userId}_{$this->numeroProcesso}";
    }

    /**
     * Execute o job.
     */
    public function handle(): void
    {
        try {
            // Proteção contra duplicação
            $analiseEmAndamento = DocumentAnalysis::where('user_id', $this->userId)
                ->where('numero_processo', $this->numeroProcesso)
                ->where('status', 'processing')
                ->first();

            if ($analiseEmAndamento) {
                Log::warning('AnalyzeProcessDocuments: Análise duplicada bloqueada', [
                    'user_id' => $this->userId,
                    'numero_processo' => $this->numeroProcesso,
                    'analise_existente_id' => $analiseEmAndamento->id,
                ]);
                return;
            }

            $user = User::find($this->userId);
            if (!$user) {
                Log::error('AnalyzeProcessDocuments: Usuário não encontrado', ['user_id' => $this->userId]);
                return;
            }

            Log::info('AnalyzeProcessDocuments: Iniciando processamento paralelo', [
                'numero_processo' => $this->numeroProcesso,
                'total_documentos' => count($this->documentos),
            ]);

            // Notifica início do download
            $this->sendNotification(
                $user,
                'Baixando Documentos',
                "Iniciando download paralelo de " . count($this->documentos) . " documento(s) do e-Proc para o processo {$this->numeroProcesso}.",
                'info'
            );

            // Formata classe e assuntos
            $classeProcessual = $this->contextoDados['classeProcessualNome']
                ?? $this->contextoDados['classeProcessual']
                ?? null;
            $assuntos = $this->formatAssuntosString($this->contextoDados['assunto'] ?? []);

            // Cria registro principal da análise
            $documentAnalysis = DocumentAnalysis::create([
                'user_id' => $this->userId,
                'numero_processo' => $this->numeroProcesso,
                'classe_processual' => $classeProcessual,
                'assuntos' => $assuntos,
                'descricao_documento' => count($this->documentos) . ' documento(s) do processo',
                'status' => 'processing',
                'total_documents' => count($this->documentos),
                'job_parameters' => [
                    'documentos' => $this->documentos,
                    'contextoDados' => $this->contextoDados,
                    'promptTemplate' => $this->promptTemplate,                    // Para parecer final (REDUCE)
                    'documentAnalysisPrompt' => $this->documentAnalysisPrompt,    // Para análise de documentos (MAP)
                    'aiProvider' => $this->aiProvider,
                    'deepThinkingEnabled' => $this->deepThinkingEnabled,
                ],
            ]);

            // Inicializa para map-reduce
            $documentAnalysis->initializeMapReduce(count($this->documentos));

            Log::info('AnalyzeProcessDocuments: Registro de análise criado', [
                'analysis_id' => $documentAnalysis->id,
            ]);

            // Cria jobs de download para cada documento
            // Escalonamento de 1s entre jobs para evitar rate limiting do webservice
            $downloadJobs = [];
            foreach ($this->documentos as $index => $documento) {
                $job = new DownloadDocumentJob(
                    $documentAnalysis->id,
                    $index,
                    $documento,
                    $this->numeroProcesso,
                    $this->userLogin,
                    $this->senha,
                    $this->chave
                );
                $job->delay(now()->addSeconds($index));
                $downloadJobs[] = $job;
            }

            // Armazena dados necessários para callbacks
            $analysisId = $documentAnalysis->id;
            $aiProvider = $this->aiProvider;
            $deepThinkingEnabled = $this->deepThinkingEnabled;
            $contextoDados = $this->contextoDados;
            $aiModelId = $this->aiModelId;
            $mapModelId = $this->mapModelId;
            $userId = $this->userId;

            // Dispara batch de downloads paralelos
            Bus::batch($downloadJobs)
                ->name("download_docs_analysis_{$analysisId}")
                ->onQueue('downloads')
                ->allowFailures() // Permite que alguns downloads falhem sem cancelar o batch
                ->then(function (Batch $batch) use ($analysisId, $aiProvider, $deepThinkingEnabled, $contextoDados, $aiModelId, $mapModelId, $userId) {
                    // Callback de sucesso: todos os downloads concluídos
                    Log::info('AnalyzeProcessDocuments: Batch de downloads concluído', [
                        'analysis_id' => $analysisId,
                        'batch_id' => $batch->id,
                        'total_jobs' => $batch->totalJobs,
                        'failed_jobs' => $batch->failedJobs,
                    ]);

                    // Dispara fase MAP após downloads
                    DispatchMapPhaseJob::dispatch(
                        $analysisId,
                        $aiProvider,
                        $deepThinkingEnabled,
                        $contextoDados,
                        $aiModelId,
                        $userId,
                        'auto',
                        $mapModelId
                    )->onQueue('analysis');
                })
                ->catch(function (Batch $batch, \Throwable $e) use ($analysisId, $userId) {
                    // Callback de erro (chamado quando o primeiro job falha)
                    Log::error('AnalyzeProcessDocuments: Erro no batch de downloads', [
                        'analysis_id' => $analysisId,
                        'batch_id' => $batch->id,
                        'error' => $e->getMessage(),
                    ]);
                })
                ->finally(function (Batch $batch) use ($analysisId) {
                    // Callback final (sempre executado)
                    Log::info('AnalyzeProcessDocuments: Batch de downloads finalizado', [
                        'analysis_id' => $analysisId,
                        'batch_id' => $batch->id,
                        'pending_jobs' => $batch->pendingJobs,
                        'failed_jobs' => $batch->failedJobs,
                    ]);
                })
                ->dispatch();

            Log::info('AnalyzeProcessDocuments: Batch de downloads disparado', [
                'analysis_id' => $documentAnalysis->id,
                'total_jobs' => count($downloadJobs),
            ]);

        } catch (\Exception $e) {
            Log::error('AnalyzeProcessDocuments: Erro geral', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if (isset($documentAnalysis)) {
                $documentAnalysis->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }

            $this->sendNotification(
                User::find($this->userId),
                'Análise Falhou',
                'Erro: ' . $e->getMessage(),
                'danger'
            );

            throw $e;
        }
    }

    /**
     * Envia notificação para o usuário
     */
    private function sendNotification(?User $user, string $title, string $body, string $status = 'info'): void
    {
        if (!$user) {
            return;
        }

        try {
            NotificationService::send($user, $title, $body, $status);
        } catch (\Exception $e) {
            Log::warning('AnalyzeProcessDocuments: Erro ao enviar notificação', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Formata array de assuntos para string
     */
    private function formatAssuntosString(array $assuntos): ?string
    {
        if (empty($assuntos)) {
            return null;
        }

        $nomes = array_map(function ($assunto) {
            return $assunto['nomeAssunto']
                ?? $assunto['descricao']
                ?? $assunto['codigoAssunto']
                ?? $assunto['codigoNacional']
                ?? null;
        }, $assuntos);

        $nomes = array_filter($nomes);

        return !empty($nomes) ? implode(', ', $nomes) : null;
    }
}
