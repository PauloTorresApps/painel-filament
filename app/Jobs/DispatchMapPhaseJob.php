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
use App\Services\NotificationService;
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

    public int $timeout;
    public int $tries;

    public function __construct(
        public int $analysisId,
        public string $aiProvider,
        public bool $deepThinkingEnabled,
        public array $contextoDados,
        public ?string $aiModelId,              // Modelo para REDUCE (parecer final)
        public int $userId,
        public string $reduceStrategy = 'auto', // 'auto', 'refine', 'batch'
        public ?string $mapModelId = null        // Modelo para MAP (análise de documentos)
    ) {
        $this->timeout = config('analysis.jobs.dispatch_map.timeout', 120);
        $this->tries = config('analysis.jobs.dispatch_map.tries', 3);
    }

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

            // Calcula total de caracteres (LENGTH é compatível com MySQL, PostgreSQL e SQLite)
            $totalCharacters = $documentAnalysis->microAnalyses()
                ->where('status', 'pending')
                ->where('reduce_level', 0)
                ->sum(DB::raw('LENGTH(extracted_text)'));

            $documentAnalysis->update(['total_characters' => $totalCharacters]);

            // Detecta documentos grandes e decide estratégia de MAP
            $regularDocs = [];
            $largeDocs = [];

            foreach ($pendingMicroAnalyses as $microAnalysis) {
                $textLength = mb_strlen($microAnalysis->extracted_text ?? '');

                if ($textLength > config('analysis.thresholds.large_document_chars', 100000)) {
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
            $providerName = 'OpenRouter';

            $largeDocsMsg = count($largeDocs) > 0
                ? " (" . count($largeDocs) . " documento(s) extenso(s) serão processados em partes)"
                : "";

            $this->sendNotification(
                'Fase 1/2: Análise Individual',
                "Download concluído! A {$providerName} está analisando {$microAnalysesCount} documento(s) em paralelo.{$largeDocsMsg}",
                'info'
            );

            // Busca o prompt customizado de análise de documentos (se configurado)
            $customAnalysisPrompt = $documentAnalysis->job_parameters['documentAnalysisPrompt'] ?? null;

            // Cria jobs de MAP - diferentes tipos baseado no tamanho
            $mapJobs = [];

            // Usa modelo MAP específico, com fallback para o modelo REDUCE
            $mapModel = $this->mapModelId ?? $this->aiModelId;

            // Jobs para documentos regulares
            foreach ($regularDocs as $microAnalysis) {
                $mapJobs[] = new MapDocumentAnalysisJob(
                    $microAnalysis->id,
                    $this->aiProvider,
                    $this->deepThinkingEnabled,
                    $this->contextoDados,
                    $mapModel,
                    $customAnalysisPrompt  // Prompt customizado para análise de documentos
                );
            }

            // Jobs para documentos grandes (sub-map-reduce)
            foreach ($largeDocs as $microAnalysis) {
                $mapJobs[] = new ChunkLargeDocumentJob(
                    $microAnalysis->id,
                    $this->aiProvider,
                    $this->deepThinkingEnabled,
                    $this->contextoDados,
                    $mapModel
                    // TODO: Adicionar customAnalysisPrompt ao ChunkLargeDocumentJob se necessário
                );
            }

            // Determina estratégia de REDUCE
            $useRefineStrategy = $this->shouldUseRefineStrategy($microAnalysesCount);

            Log::info('DispatchMapPhaseJob: Estratégias selecionadas', [
                'analysis_id' => $this->analysisId,
                'regular_docs' => count($regularDocs),
                'large_docs' => count($largeDocs),
                'reduce_strategy' => $useRefineStrategy ? 'refine' : 'batch',
                'map_model' => $mapModel,
                'reduce_model' => $this->aiModelId,
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
                ->then(function (Batch $batch) use ($analysisId, $aiProvider, $deepThinkingEnabled, $aiModelId, $useRefineStrategy, $contextoDados, $userId) {
                    // Callback de sucesso: todos os MAPs concluídos
                    Log::info('DispatchMapPhaseJob: Batch de MAPs concluído', [
                        'analysis_id' => $analysisId,
                        'batch_id' => $batch->id,
                        'total_jobs' => $batch->totalJobs,
                        'failed_jobs' => $batch->failedJobs,
                        'reduce_strategy' => $useRefineStrategy ? 'refine' : 'batch',
                    ]);

                    // Circuit breaker: aborta se taxa de falha exceder limite
                    $threshold = config('analysis.circuit_breaker.failure_threshold', 0.25);
                    $minJobs = config('analysis.circuit_breaker.min_jobs', 4);

                    if ($batch->totalJobs >= $minJobs && $batch->totalJobs > 0) {
                        $failureRate = $batch->failedJobs / $batch->totalJobs;

                        if ($failureRate > $threshold) {
                            $failedPct = round($failureRate * 100);
                            $thresholdPct = round($threshold * 100);

                            Log::error('DispatchMapPhaseJob: Circuit breaker ativado', [
                                'analysis_id' => $analysisId,
                                'failed_jobs' => $batch->failedJobs,
                                'total_jobs' => $batch->totalJobs,
                                'failure_rate' => "{$failedPct}%",
                                'threshold' => "{$thresholdPct}%",
                            ]);

                            $documentAnalysis = DocumentAnalysis::find($analysisId);
                            if ($documentAnalysis) {
                                $documentAnalysis->markAsFailed(
                                    "Análise abortada: {$batch->failedJobs} de {$batch->totalJobs} documentos falharam ({$failedPct}%). Limite tolerado: {$thresholdPct}%."
                                );

                                NotificationService::error(
                                    User::find($userId),
                                    'Análise Abortada',
                                    "A análise do processo {$documentAnalysis->numero_processo} foi abortada: {$failedPct}% dos documentos falharam na fase MAP (limite: {$thresholdPct}%). Verifique a conectividade com a API e tente novamente."
                                );
                            }

                            return;
                        }
                    }

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
        return $docCount <= config('analysis.thresholds.refine_max_documents', 20);
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
