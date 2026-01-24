<?php

namespace App\Jobs;

use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use App\Models\User;
use App\Services\EprocService;
use App\Services\PdfToTextService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Filament\Notifications\Notification as FilamentNotification;

/**
 * Job orquestrador para análise de documentos de processo via map-reduce.
 *
 * Responsabilidades:
 * 1. Baixar documentos do e-Proc
 * 2. Extrair texto dos PDFs
 * 3. Criar registros de micro-análises
 * 4. Disparar jobs de MAP para processamento paralelo
 */
class AnalyzeProcessDocuments implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 0; // Sem timeout - permite downloads longos
    public int $tries = 2;

    public function __construct(
        public int $userId,
        public string $numeroProcesso,
        public array $documentos,
        public array $contextoDados,
        public string $promptTemplate,
        public string $aiProvider,
        public bool $deepThinkingEnabled,
        public string $userLogin,
        public string $senha,
        public int $judicialUserId
    ) {}

    /**
     * Chave única para evitar duplicação
     */
    public function uniqueId(): string
    {
        return "analyze_process_{$this->userId}_{$this->numeroProcesso}";
    }

    public int $uniqueFor = 600; // 10 minutos

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

            $pdfService = new PdfToTextService();
            $eprocService = new EprocService($this->userLogin, $this->senha);

            Log::info('AnalyzeProcessDocuments: Iniciando download de documentos', [
                'numero_processo' => $this->numeroProcesso,
                'total_documentos' => count($this->documentos),
            ]);

            // Notifica início do download
            $this->sendNotification(
                $user,
                'Baixando Documentos',
                "Baixando " . count($this->documentos) . " documento(s) do e-Proc para o processo {$this->numeroProcesso}.",
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
                    'promptTemplate' => $this->promptTemplate,
                    'aiProvider' => $this->aiProvider,
                    'deepThinkingEnabled' => $this->deepThinkingEnabled,
                ],
            ]);

            // Inicializa para map-reduce
            $documentAnalysis->initializeMapReduce(count($this->documentos));

            Log::info('AnalyzeProcessDocuments: Registro de análise criado', [
                'analysis_id' => $documentAnalysis->id,
            ]);

            // Processa cada documento e cria micro-análises
            $microAnalysesCreated = 0;
            $totalCharacters = 0;

            foreach ($this->documentos as $index => $documento) {
                try {
                    // Busca o conteúdo do documento
                    $documentoCompleto = $this->fetchDocumento($eprocService, $documento['idDocumento']);

                    if (!$documentoCompleto || empty($documentoCompleto['conteudo'])) {
                        Log::warning('AnalyzeProcessDocuments: Documento sem conteúdo', [
                            'id_documento' => $documento['idDocumento']
                        ]);

                        // Cria micro-análise com status de falha
                        DocumentMicroAnalysis::create([
                            'document_analysis_id' => $documentAnalysis->id,
                            'document_index' => $index,
                            'id_documento' => $documento['idDocumento'],
                            'descricao' => $documento['descricao'] ?? "Documento " . ($index + 1),
                            'status' => 'failed',
                            'error_message' => 'Documento sem conteúdo',
                            'reduce_level' => 0,
                        ]);
                        continue;
                    }

                    // Extrai texto do PDF
                    $texto = $pdfService->extractText(
                        $documentoCompleto['conteudo'],
                        "doc_{$documento['idDocumento']}.pdf"
                    );

                    $totalCharacters += mb_strlen($texto);

                    // Cria registro de micro-análise
                    $microAnalysis = DocumentMicroAnalysis::create([
                        'document_analysis_id' => $documentAnalysis->id,
                        'document_index' => $index,
                        'id_documento' => $documento['idDocumento'],
                        'descricao' => $documento['descricao'] ?? "Documento " . ($index + 1),
                        'extracted_text' => $texto,
                        'status' => 'pending',
                        'reduce_level' => 0, // Nível MAP
                    ]);

                    $microAnalysesCreated++;

                    Log::info('AnalyzeProcessDocuments: Micro-análise criada', [
                        'micro_id' => $microAnalysis->id,
                        'document_index' => $index,
                        'chars' => mb_strlen($texto),
                    ]);

                } catch (\Exception $e) {
                    Log::error('AnalyzeProcessDocuments: Erro ao processar documento', [
                        'id_documento' => $documento['idDocumento'],
                        'error' => $e->getMessage()
                    ]);

                    // Cria micro-análise com status de falha
                    DocumentMicroAnalysis::create([
                        'document_analysis_id' => $documentAnalysis->id,
                        'document_index' => $index,
                        'id_documento' => $documento['idDocumento'],
                        'descricao' => $documento['descricao'] ?? "Documento " . ($index + 1),
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                        'reduce_level' => 0,
                    ]);
                }

                // Notifica progresso a cada 10 documentos
                if (($index + 1) % 10 === 0) {
                    $this->sendNotification(
                        $user,
                        'Progresso',
                        "Baixados " . ($index + 1) . " de " . count($this->documentos) . " documentos",
                        'info'
                    );
                }
            }

            // Atualiza total de caracteres
            $documentAnalysis->update(['total_characters' => $totalCharacters]);

            if ($microAnalysesCreated === 0) {
                $documentAnalysis->update([
                    'status' => 'failed',
                    'error_message' => 'Nenhum documento pôde ser processado'
                ]);

                $this->sendNotification(
                    $user,
                    'Análise Falhou',
                    'Nenhum documento pôde ser processado',
                    'danger'
                );
                return;
            }

            Log::info('AnalyzeProcessDocuments: Download concluído, disparando jobs de MAP', [
                'analysis_id' => $documentAnalysis->id,
                'micro_analyses_created' => $microAnalysesCreated,
                'total_characters' => $totalCharacters,
            ]);

            // Notifica que a análise vai começar
            $providerName = match ($this->aiProvider) {
                'gemini' => 'Google Gemini',
                'deepseek' => 'DeepSeek',
                'openai' => 'OpenAI',
                default => 'IA'
            };

            $this->sendNotification(
                $user,
                'Iniciando Análise por IA',
                "Download concluído! A {$providerName} está analisando {$microAnalysesCreated} documento(s) em paralelo.",
                'info'
            );

            // Dispara jobs de MAP para cada micro-análise pendente
            $this->dispatchMapJobs($documentAnalysis);

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
     * Dispara jobs de MAP para as micro-análises pendentes
     */
    private function dispatchMapJobs(DocumentAnalysis $documentAnalysis): void
    {
        $pendingMicroAnalyses = $documentAnalysis->microAnalyses()
            ->where('status', 'pending')
            ->where('reduce_level', 0)
            ->get();

        $jobs = [];

        foreach ($pendingMicroAnalyses as $microAnalysis) {
            $jobs[] = new MapDocumentAnalysisJob(
                $microAnalysis->id,
                $this->aiProvider,
                $this->deepThinkingEnabled,
                $this->contextoDados
            );
        }

        if (empty($jobs)) {
            Log::warning('AnalyzeProcessDocuments: Nenhum job de MAP para disparar');
            return;
        }

        // Dispara todos os jobs na fila de análise
        foreach ($jobs as $job) {
            dispatch($job)->onQueue('analysis');
        }

        Log::info('AnalyzeProcessDocuments: Jobs de MAP disparados', [
            'analysis_id' => $documentAnalysis->id,
            'total_jobs' => count($jobs),
        ]);
    }

    /**
     * Busca o documento completo do webservice
     */
    private function fetchDocumento(EprocService $eprocService, string $idDocumento): ?array
    {
        try {
            $resultado = $eprocService->consultarDocumentosProcesso(
                $this->numeroProcesso,
                [$idDocumento],
                true
            );

            $documentos = null;

            if (isset($resultado['Body']['respostaConsultarDocumentosProcesso']['documentos'])) {
                $documentos = $resultado['Body']['respostaConsultarDocumentosProcesso']['documentos'];
            } elseif (isset($resultado['documento'])) {
                $documentos = $resultado['documento'];
            } else {
                return null;
            }

            if (empty($documentos)) {
                return null;
            }

            if (isset($documentos['idDocumento'])) {
                $documentos = [$documentos];
            }

            foreach ($documentos as $doc) {
                if ($doc['idDocumento'] == $idDocumento) {
                    $conteudoBase64 = null;

                    if (is_array($doc['conteudo'] ?? null) && isset($doc['conteudo']['conteudo'])) {
                        $conteudoBase64 = $doc['conteudo']['conteudo'];
                    } elseif (is_string($doc['conteudo'] ?? null)) {
                        $conteudoBase64 = $doc['conteudo'];
                    }

                    return [
                        'conteudo' => $conteudoBase64,
                        'descricao' => $doc['descricao'] ?? null,
                    ];
                }
            }

            return null;

        } catch (\Exception $e) {
            Log::error('AnalyzeProcessDocuments: Erro ao buscar documento', [
                'id_documento' => $idDocumento,
                'error' => $e->getMessage()
            ]);
            return null;
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
            FilamentNotification::make()
                ->title($title)
                ->body($body)
                ->status($status)
                ->sendToDatabase($user);
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
