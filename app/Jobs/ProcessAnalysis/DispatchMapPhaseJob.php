<?php

namespace App\Jobs\ProcessAnalysis;

use App\Jobs\Middleware\OtelJobMiddleware;
use App\Models\DocumentAnalysis;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\NotificationService;
use App\Pipeline\Graph\Conditions\CircuitBreakerTrippedCondition;
use App\Pipeline\Graph\Conditions\IsLargeDocumentCondition;
use App\Pipeline\Graph\Conditions\SkipMapForReprocessingCondition;
use App\Pipeline\Graph\Conditions\UseRefineStrategyCondition;
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

    public function middleware(): array
    {
        return [new OtelJobMiddleware()];
    }

    private function dispatchReducePhase(bool $useRefineStrategy, int $analysisId, string $aiProvider, bool $deepThinkingEnabled, ?string $aiModelId, array $contextoDados): void
    {
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

            $completedMicroAnalysesCount = $documentAnalysis->microAnalyses()
                ->where('status', 'completed')
                ->where('reduce_level', 0)
                ->count();

            if ($microAnalysesCount === 0 && $completedMicroAnalysesCount === 0) {
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

            // Descobre estratégia antecipadamente (pode ser recalculada após o MAP)
            $originalTotalDocs = $microAnalysesCount + $completedMicroAnalysesCount + $failedDownloads;
            $useRefineStrategy = $this->shouldUseRefineStrategy($originalTotalDocs, null);

            // Se não há jobs Map pendentes, mas existem os completados (Ex: Reprocessar apenas Reduce), dispara logo o Reduce
            if ((new SkipMapForReprocessingCondition())->evaluate($microAnalysesCount, $completedMicroAnalysesCount)) {
                $completedChars = (int) $documentAnalysis->microAnalyses()
                    ->where('status', 'completed')
                    ->where('reduce_level', 0)
                    ->sum(DB::raw('LENGTH(micro_analysis)'));
                $useRefineStrategy = $this->shouldUseRefineStrategy($completedMicroAnalysesCount, $completedChars);

                Log::info('DispatchMapPhaseJob: Pulando fase MAP e indo direto para REDUCE', [
                    'analysis_id' => $this->analysisId,
                    'completed_count' => $completedMicroAnalysesCount,
                    'completed_chars' => $completedChars,
                    'reduce_strategy' => $useRefineStrategy ? 'refine' : 'batch',
                ]);
                $this->dispatchReducePhase($useRefineStrategy, $this->analysisId, $this->aiProvider, $this->deepThinkingEnabled, $this->aiModelId, $this->contextoDados);
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

            $isLargeDocument = new IsLargeDocumentCondition();

            foreach ($pendingMicroAnalyses as $microAnalysis) {
                $textLength = mb_strlen($microAnalysis->extracted_text ?? '');

                if ($isLargeDocument->evaluate($textLength)) {
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
                    $mapModel,
                    $customAnalysisPrompt
                );
            }

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
                ->then(function (Batch $batch) use ($analysisId, $aiProvider, $deepThinkingEnabled, $aiModelId, $contextoDados, $userId) {
                    // Callback de sucesso: todos os MAPs concluídos
                    Log::info('DispatchMapPhaseJob: Batch de MAPs concluído', [
                        'analysis_id' => $analysisId,
                        'batch_id' => $batch->id,
                        'total_jobs' => $batch->totalJobs,
                        'failed_jobs' => $batch->failedJobs,
                    ]);

                    // Circuit breaker: aborta se taxa de falha exceder limite
                    $threshold = (float) config('analysis.circuit_breaker.failure_threshold', 0.25);
                    $circuitBreaker = new CircuitBreakerTrippedCondition();

                    if ($circuitBreaker->evaluate($batch->failedJobs, $batch->totalJobs, $threshold)) {
                            $failedPct = $batch->totalJobs > 0
                                ? round(($batch->failedJobs / $batch->totalJobs) * 100)
                                : 0;
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

                    // Recalcula estratégia com base no MAP concluído para evitar
                    // cair no Refine sequencial quando o volume agregado ficou alto.
                    $documentAnalysis = DocumentAnalysis::find($analysisId);
                    $completedCount = (int) ($documentAnalysis?->microAnalyses()
                        ->where('status', 'completed')
                        ->where('reduce_level', 0)
                        ->count() ?? 0);
                    $completedChars = (int) ($documentAnalysis?->microAnalyses()
                        ->where('status', 'completed')
                        ->where('reduce_level', 0)
                        ->sum(DB::raw('LENGTH(micro_analysis)')) ?? 0);

                    $dispatchJob = new DispatchMapPhaseJob(
                        $analysisId,
                        $aiProvider,
                        $deepThinkingEnabled,
                        $contextoDados,
                        $aiModelId,
                        $userId,
                        'auto'
                    );
                    $useRefineStrategy = $dispatchJob->shouldUseRefineStrategy($completedCount, $completedChars);

                    Log::info('DispatchMapPhaseJob: Estratégia REDUCE recalculada após MAP', [
                        'analysis_id' => $analysisId,
                        'completed_docs' => $completedCount,
                        'completed_chars' => $completedChars,
                        'reduce_strategy' => $useRefineStrategy ? 'refine' : 'batch',
                    ]);

                    // Escolhe a estratégia de REDUCE
                    $dispatchJob = new DispatchMapPhaseJob(
                        $analysisId, $aiProvider, $deepThinkingEnabled, $contextoDados, $aiModelId, $userId, 'auto'
                    );
                    $dispatchJob->dispatchReducePhase($useRefineStrategy, $analysisId, $aiProvider, $deepThinkingEnabled, $aiModelId, $contextoDados);
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
                ->finally(function (Batch $batch) use ($analysisId, $userId, $aiProvider, $deepThinkingEnabled, $aiModelId, $contextoDados) {
                    Log::info('DispatchMapPhaseJob: Batch de MAPs finalizado', [
                        'analysis_id' => $analysisId,
                        'batch_id' => $batch->id,
                        'pending_jobs' => $batch->pendingJobs,
                        'failed_jobs' => $batch->failedJobs,
                    ]);

                    if ($batch->cancelled()) {
                        $documentAnalysis = DocumentAnalysis::find($analysisId);
                        if ($documentAnalysis) {
                            $failedPct = $batch->totalJobs > 0 ? round(($batch->failedJobs / $batch->totalJobs) * 100) : 0;
                            $threshold = config('analysis.circuit_breaker.failure_threshold', 0.25);
                            $thresholdPct = round($threshold * 100);

                            // Atualiza caso n˜ão tenha sido atualizado por um Catch/Failure manual
                            if ($documentAnalysis->status !== 'failed') {
                                $documentAnalysis->markAsFailed(
                                    "Análise abortada antecipadamente: Limite dinâmico de falhas excedido ({$failedPct}% falharam). Limite tolerado: {$thresholdPct}%."
                                );
                            }

                            NotificationService::error(
                                User::find($userId),
                                'Análise Abortada (Circuit Breaker)',
                                "A análise do processo {$documentAnalysis->numero_processo} foi interrompida antecipadamente devido à alta taxa de erros da IA ({$failedPct}% de falhas). Verifique a conectividade da API ou seu limite de créditos."
                            );
                        }

                        return;
                    }

                    // Auto-healing: com allowFailures(), o callback then() não executa em falhas parciais.
                    // Se houver MAP concluído e nenhum REDUCE iniciado, dispara transição para evitar estado zumbi em MAP.
                    if ($batch->failedJobs <= 0) {
                        return;
                    }

                    $documentAnalysis = DocumentAnalysis::find($analysisId);
                    if (!$documentAnalysis) {
                        return;
                    }

                    if (in_array($documentAnalysis->status, ['failed', 'cancelled', 'completed'], true)) {
                        return;
                    }

                    if ($documentAnalysis->current_phase === DocumentAnalysis::PHASE_REDUCE) {
                        return;
                    }

                    $completedCount = (int) $documentAnalysis->microAnalyses()
                        ->where('status', 'completed')
                        ->where('reduce_level', 0)
                        ->count();

                    if ($completedCount === 0) {
                        $documentAnalysis->markAsFailed(
                            "Nenhuma análise MAP concluída após falhas parciais do batch {$batch->id}."
                        );

                        NotificationService::error(
                            User::find($userId),
                            'Análise Falhou na Fase MAP',
                            "A análise do processo {$documentAnalysis->numero_processo} não teve documentos processados com sucesso na fase MAP."
                        );

                        return;
                    }

                    $alreadyHasReduceOutputs = $documentAnalysis->microAnalyses()
                        ->where('reduce_level', '>', 0)
                        ->exists();

                    if ($alreadyHasReduceOutputs) {
                        return;
                    }

                    $completedChars = (int) $documentAnalysis->microAnalyses()
                        ->where('status', 'completed')
                        ->where('reduce_level', 0)
                        ->sum(DB::raw('LENGTH(micro_analysis)'));

                    $dispatchJob = new DispatchMapPhaseJob(
                        $analysisId,
                        $aiProvider,
                        $deepThinkingEnabled,
                        $contextoDados,
                        $aiModelId,
                        $userId,
                        'auto'
                    );

                    $useRefineStrategy = $dispatchJob->shouldUseRefineStrategy($completedCount, $completedChars);

                    Log::warning('DispatchMapPhaseJob: Auto-healing MAP->REDUCE após falha parcial', [
                        'analysis_id' => $analysisId,
                        'batch_id' => $batch->id,
                        'failed_jobs' => $batch->failedJobs,
                        'completed_docs' => $completedCount,
                        'completed_chars' => $completedChars,
                        'reduce_strategy' => $useRefineStrategy ? 'refine' : 'batch',
                    ]);

                    $dispatchJob->dispatchReducePhase(
                        $useRefineStrategy,
                        $analysisId,
                        $aiProvider,
                        $deepThinkingEnabled,
                        $aiModelId,
                        $contextoDados
                    );
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
    private function shouldUseRefineStrategy(int $docCount, ?int $totalChars): bool
    {
        return (new UseRefineStrategyCondition())->evaluate(
            docCount: $docCount,
            totalChars: $totalChars,
            reduceStrategy: $this->reduceStrategy
        );
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
