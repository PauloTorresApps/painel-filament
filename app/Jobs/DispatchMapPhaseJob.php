<?php

namespace App\Jobs;

use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Filament\Notifications\Notification as FilamentNotification;

/**
 * Job responsável por iniciar a fase MAP após os downloads.
 *
 * Implementa a "Estratégia de Divisão por Escala":
 * - Documentos pequenos (<100k chars): MapDocumentAnalysisJob padrão
 * - Documentos grandes (>100k chars): ChunkLargeDocumentJob (sub-map-reduce)
 *
 * Após o MAP, escolhe a estratégia de REDUCE:
 * - Poucos documentos (<= 20): RefineReduceJob (narrativa sequencial)
 * - Muitos documentos (> 20): ReduceDocumentAnalysisJob (batch paralelo)
 */
class DispatchMapPhaseJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;
    public int $tries = 3;

    // Limites para escolha de estratégia
    private const REFINE_THRESHOLD = 20; // Usa refine para <= 20 docs
    private const LARGE_DOC_THRESHOLD = 100000; // 100k chars = documento grande

    public function __construct(
        public int $analysisId,
        public string $aiProvider,
        public bool $deepThinkingEnabled,
        public array $contextoDados,
        public ?string $aiModelId,
        public int $userId,
        public string $reduceStrategy = 'auto' // 'auto', 'refine', 'batch'
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $documentAnalysis = DocumentAnalysis::find($this->analysisId);

            if (!$documentAnalysis) {
                Log::error('DispatchMapPhaseJob: DocumentAnalysis não encontrada', [
                    'id' => $this->analysisId
                ]);
                return;
            }

            if ($documentAnalysis->status === 'cancelled') {
                Log::info('DispatchMapPhaseJob: Análise cancelada', [
                    'id' => $this->analysisId
                ]);
                return;
            }

            // Busca micro-análises pendentes (downloads concluídos com sucesso)
            $pendingMicroAnalyses = $documentAnalysis->microAnalyses()
                ->where('status', 'pending')
                ->where('reduce_level', 0)
                ->get();

            $microAnalysesCount = $pendingMicroAnalyses->count();
            $failedDownloads = $documentAnalysis->microAnalyses()
                ->where('status', 'failed')
                ->where('reduce_level', 0)
                ->count();

            Log::info('DispatchMapPhaseJob: Verificando documentos baixados', [
                'analysis_id' => $this->analysisId,
                'pending_count' => $microAnalysesCount,
                'failed_downloads' => $failedDownloads,
            ]);

            if ($microAnalysesCount === 0) {
                $documentAnalysis->update([
                    'status' => 'failed',
                    'error_message' => 'Nenhum documento pôde ser baixado com sucesso'
                ]);

                $this->sendNotification(
                    'Análise Falhou',
                    'Nenhum documento pôde ser baixado com sucesso',
                    'danger'
                );
                return;
            }

            // Calcula total de caracteres
            $totalCharacters = $documentAnalysis->microAnalyses()
                ->where('status', 'pending')
                ->where('reduce_level', 0)
                ->sum(DB::raw('CHAR_LENGTH(extracted_text)'));

            $documentAnalysis->update(['total_characters' => $totalCharacters]);

            // Detecta documentos grandes e decide estratégia de MAP
            $regularDocs = [];
            $largeDocs = [];

            foreach ($pendingMicroAnalyses as $microAnalysis) {
                $textLength = mb_strlen($microAnalysis->extracted_text ?? '');

                if ($textLength > self::LARGE_DOC_THRESHOLD) {
                    $largeDocs[] = $microAnalysis;
                    Log::info('DispatchMapPhaseJob: Documento grande detectado', [
                        'micro_id' => $microAnalysis->id,
                        'text_length' => $textLength,
                        'descricao' => $microAnalysis->descricao,
                    ]);
                } else {
                    $regularDocs[] = $microAnalysis;
                }
            }

            // Atualiza para fase MAP
            $documentAnalysis->startMapPhase();

            // Notifica que a análise vai começar
            $providerName = match ($this->aiProvider) {
                'gemini' => 'Google Gemini',
                'deepseek' => 'DeepSeek',
                'openai' => 'OpenAI',
                default => 'IA'
            };

            $largeDocsMsg = count($largeDocs) > 0
                ? " (" . count($largeDocs) . " documento(s) extenso(s) serão processados em partes)"
                : "";

            $this->sendNotification(
                'Fase 1/2: Análise Individual',
                "Download concluído! A {$providerName} está analisando {$microAnalysesCount} documento(s) em paralelo.{$largeDocsMsg}",
                'info'
            );

            // Cria jobs de MAP - diferentes tipos baseado no tamanho
            $mapJobs = [];

            // Jobs para documentos regulares
            foreach ($regularDocs as $microAnalysis) {
                $mapJobs[] = new MapDocumentAnalysisJob(
                    $microAnalysis->id,
                    $this->aiProvider,
                    $this->deepThinkingEnabled,
                    $this->contextoDados,
                    $this->aiModelId
                );
            }

            // Jobs para documentos grandes (sub-map-reduce)
            foreach ($largeDocs as $microAnalysis) {
                $mapJobs[] = new ChunkLargeDocumentJob(
                    $microAnalysis->id,
                    $this->aiProvider,
                    $this->deepThinkingEnabled,
                    $this->contextoDados,
                    $this->aiModelId
                );
            }

            // Determina estratégia de REDUCE
            $useRefineStrategy = $this->shouldUseRefineStrategy($microAnalysesCount);

            Log::info('DispatchMapPhaseJob: Estratégias selecionadas', [
                'analysis_id' => $this->analysisId,
                'regular_docs' => count($regularDocs),
                'large_docs' => count($largeDocs),
                'reduce_strategy' => $useRefineStrategy ? 'refine' : 'batch',
            ]);

            // Armazena dados para callbacks
            $analysisId = $this->analysisId;
            $aiProvider = $this->aiProvider;
            $deepThinkingEnabled = $this->deepThinkingEnabled;
            $aiModelId = $this->aiModelId;
            $userId = $this->userId;
            $contextoDados = $this->contextoDados;

            // Dispara batch de MAPs paralelos
            Bus::batch($mapJobs)
                ->name("map_analysis_{$analysisId}")
                ->onQueue('analysis')
                ->allowFailures()
                ->then(function (Batch $batch) use ($analysisId, $aiProvider, $deepThinkingEnabled, $aiModelId, $useRefineStrategy, $contextoDados) {
                    // Callback de sucesso: todos os MAPs concluídos
                    Log::info('DispatchMapPhaseJob: Batch de MAPs concluído', [
                        'analysis_id' => $analysisId,
                        'batch_id' => $batch->id,
                        'total_jobs' => $batch->totalJobs,
                        'failed_jobs' => $batch->failedJobs,
                        'reduce_strategy' => $useRefineStrategy ? 'refine' : 'batch',
                    ]);

                    // Escolhe a estratégia de REDUCE
                    if ($useRefineStrategy) {
                        // Estratégia de Refinamento Sequencial (narrativa melhor)
                        $documentAnalysis = DocumentAnalysis::find($analysisId);
                        $promptTemplate = $documentAnalysis->job_parameters['promptTemplate'] ?? '';

                        $refineJob = new RefineReduceJob(
                            $analysisId,
                            $aiProvider,
                            $deepThinkingEnabled,
                            $promptTemplate,
                            $aiModelId
                        );
                        $refineJob->setContextoDados($contextoDados);

                        dispatch($refineJob)->onQueue('analysis');
                    } else {
                        // Estratégia de Batch Paralelo (mais rápido para muitos docs)
                        ReduceDocumentAnalysisJob::dispatch(
                            $analysisId,
                            $aiProvider,
                            $deepThinkingEnabled,
                            '', // promptTemplate será obtido do job_parameters
                            $aiModelId,
                            1   // Primeiro nível de reduce
                        )->onQueue('analysis');
                    }
                })
                ->catch(function (Batch $batch, \Throwable $e) use ($analysisId) {
                    Log::error('DispatchMapPhaseJob: Erro no batch de MAPs', [
                        'analysis_id' => $analysisId,
                        'batch_id' => $batch->id,
                        'error' => $e->getMessage(),
                    ]);
                })
                ->progress(function (Batch $batch) use ($analysisId) {
                    // Callback de progresso
                    $documentAnalysis = DocumentAnalysis::find($analysisId);
                    if ($documentAnalysis) {
                        $completed = $batch->totalJobs - $batch->pendingJobs - $batch->failedJobs;
                        $documentAnalysis->updateMapProgress($completed);
                    }
                })
                ->finally(function (Batch $batch) use ($analysisId) {
                    Log::info('DispatchMapPhaseJob: Batch de MAPs finalizado', [
                        'analysis_id' => $analysisId,
                        'batch_id' => $batch->id,
                        'pending_jobs' => $batch->pendingJobs,
                        'failed_jobs' => $batch->failedJobs,
                    ]);
                })
                ->dispatch();

            Log::info('DispatchMapPhaseJob: Batch de MAPs disparado', [
                'analysis_id' => $this->analysisId,
                'total_jobs' => count($mapJobs),
            ]);

        } catch (\Exception $e) {
            Log::error('DispatchMapPhaseJob: Erro geral', [
                'analysis_id' => $this->analysisId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $documentAnalysis = DocumentAnalysis::find($this->analysisId);
            if ($documentAnalysis) {
                $documentAnalysis->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Determina se deve usar a estratégia de Refinamento Sequencial
     */
    private function shouldUseRefineStrategy(int $docCount): bool
    {
        // Se foi especificado explicitamente
        if ($this->reduceStrategy === 'refine') {
            return true;
        }

        if ($this->reduceStrategy === 'batch') {
            return false;
        }

        // Estratégia automática baseada na quantidade de documentos
        // - Poucos documentos: refine é melhor (narrativa mais coesa)
        // - Muitos documentos: batch é mais rápido e eficiente
        return $docCount <= self::REFINE_THRESHOLD;
    }

    /**
     * Envia notificação para o usuário
     */
    private function sendNotification(string $title, string $body, string $status = 'info'): void
    {
        $user = User::find($this->userId);
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
            Log::warning('DispatchMapPhaseJob: Erro ao enviar notificação', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
