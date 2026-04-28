<?php

namespace App\Jobs\ProcessAnalysis;

use App\Jobs\Middleware\OtelJobMiddleware;
use App\Models\AiPrompt;
use App\Models\DocumentAnalysis;
use App\Models\Setting;
use App\Models\User;
use App\Mail\ProcessAnalysis\ProcessAnalysisCompleted;
use App\Services\AIServiceFactory;
use App\Services\NotificationService;
use App\Traits\HandlesJsonOutput;
use App\Traits\InjectsUpstreamInputs;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Job responsável pela fase REDUCE do map-reduce.
 * Consolida micro-análises em análises maiores até gerar a análise final.
 *
 * Arquitetura de Processamento Paralelo:
 * - Batches são processados em paralelo via Bus::batch() + ReduceBatchJob
 * - Após todos os batches de um nível, dispara o próximo nível ou gera análise final
 */
class ReduceDocumentAnalysisJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable, HandlesJsonOutput, InjectsUpstreamInputs;

    public int $timeout;
    public int $tries;
    public int $backoff;
    public int $uniqueFor;

    public function __construct(
        public int $documentAnalysisId,
        public string $aiProvider,
        public bool $deepThinkingEnabled,
        public string $promptTemplate,
        public ?string $aiModelId = null,
        public int $currentReduceLevel = 1
    ) {
        $this->timeout = config('analysis.jobs.reduce_document.timeout', 600);
        $this->tries = config('analysis.jobs.reduce_document.tries', 3);
        $this->backoff = config('analysis.jobs.reduce_document.backoff', 60);
        $this->uniqueFor = 1800;
    }

    /**
     * Chave única para evitar execução duplicada
     */
    public function uniqueId(): string
    {
        return "reduce_doc_{$this->documentAnalysisId}_level_{$this->currentReduceLevel}";
    }

    public function middleware(): array
    {
        return [new OtelJobMiddleware()];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $documentAnalysis = DocumentAnalysis::find($this->documentAnalysisId);

            if (!$documentAnalysis) {
                Log::error('ReduceDocumentAnalysisJob: DocumentAnalysis não encontrada', [
                    'id' => $this->documentAnalysisId
                ]);
                return;
            }

            // Verifica se foi cancelada
            if ($documentAnalysis->status === 'cancelled') {
                Log::info('ReduceDocumentAnalysisJob: Análise cancelada', [
                    'id' => $this->documentAnalysisId
                ]);
                return;
            }

            // Obtém promptTemplate dos parâmetros do job se não foi passado
            if (empty($this->promptTemplate) && !empty($documentAnalysis->job_parameters['promptTemplate'])) {
                $this->promptTemplate = $documentAnalysis->job_parameters['promptTemplate'];
            }

            Log::info('ReduceDocumentAnalysisJob: Iniciando REDUCE', [
                'analysis_id' => $this->documentAnalysisId,
                'reduce_level' => $this->currentReduceLevel,
                'provider' => $this->aiProvider
            ]);

            // Busca micro-análises do nível anterior que estão completas
            $previousLevel = $this->currentReduceLevel - 1;
            $microAnalyses = $documentAnalysis->microAnalyses()
                ->reduceLevel($previousLevel)
                ->completed()
                ->orderBy('document_index')
                ->get();

            if ($microAnalyses->isEmpty()) {
                Log::warning('ReduceDocumentAnalysisJob: Nenhuma micro-análise encontrada', [
                    'analysis_id' => $this->documentAnalysisId,
                    'previous_level' => $previousLevel
                ]);
                $documentAnalysis->update([
                    'status' => 'failed',
                    'error_message' => 'Nenhuma micro-análise disponível para consolidação'
                ]);
                return;
            }

            $totalMicroAnalyses = $microAnalyses->count();

            // Calcula total de níveis necessários
            $totalLevels = $this->calculateTotalLevels($totalMicroAnalyses);
            $totalBatches = (int) ceil($totalMicroAnalyses / config('analysis.reduce.batch_size', 10));

            // Se é o primeiro nível, inicializa a fase REDUCE
            if ($this->currentReduceLevel === 1) {
                $documentAnalysis->startReducePhase($totalLevels, $totalBatches);

                // Notifica o início da fase REDUCE
                $this->notifyReduceStart($documentAnalysis, $totalMicroAnalyses);
            }

            Log::info('ReduceDocumentAnalysisJob: Micro-análises a consolidar', [
                'analysis_id' => $this->documentAnalysisId,
                'count' => $totalMicroAnalyses,
                'previous_level' => $previousLevel,
                'total_levels' => $totalLevels,
                'total_batches' => $totalBatches,
            ]);

            // Se há apenas uma micro-análise ou poucas o suficiente, gera análise final diretamente
            if ($totalMicroAnalyses <= config('analysis.reduce.batch_size', 10)) {
                $this->generateFinalAnalysis($documentAnalysis, $microAnalyses);
                return;
            }

            // Caso contrário, faz reduce hierárquico em batches PARALELOS
            $this->performParallelReduce($documentAnalysis, $microAnalyses);

        } catch (\Exception $e) {
            Log::error('ReduceDocumentAnalysisJob: Erro no processamento', [
                'analysis_id' => $this->documentAnalysisId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if (isset($documentAnalysis)) {
                $documentAnalysis->update([
                    'status' => 'failed',
                    'error_message' => 'Erro na fase REDUCE: ' . $e->getMessage()
                ]);

                $this->notifyUser($documentAnalysis, 'failed', $e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Executa reduce hierárquico em batches PARALELOS usando Bus::batch()
     */
    private function performParallelReduce(DocumentAnalysis $documentAnalysis, $microAnalyses): void
    {
        $batches = $microAnalyses->chunk(config('analysis.reduce.batch_size', 10));
        $reduceBatchJobs = [];
        $batchIndex = 0;

        Log::info('ReduceDocumentAnalysisJob: Preparando batches paralelos', [
            'analysis_id' => $documentAnalysis->id,
            'total_batches' => $batches->count(),
            'reduce_level' => $this->currentReduceLevel
        ]);

        // Cria jobs de ReduceBatch para cada chunk
        foreach ($batches as $batch) {
            $batchIndex++;
            $microAnalysisIds = $batch->pluck('id')->toArray();

            $reduceBatchJobs[] = new ReduceBatchJob(
                $documentAnalysis->id,
                $microAnalysisIds,
                $batchIndex,
                $this->currentReduceLevel,
                $this->aiProvider,
                $this->deepThinkingEnabled,
                $this->aiModelId
            );
        }

        // Armazena dados para callbacks
        $analysisId = $documentAnalysis->id;
        $aiProvider = $this->aiProvider;
        $deepThinkingEnabled = $this->deepThinkingEnabled;
        $promptTemplate = $this->promptTemplate;
        $aiModelId = $this->aiModelId;
        $currentReduceLevel = $this->currentReduceLevel;

        // Dispara batch de reduces paralelos
        Bus::batch($reduceBatchJobs)
            ->name("reduce_level_{$currentReduceLevel}_analysis_{$analysisId}")
            ->onQueue('analysis')
            ->allowFailures()
            ->then(function (Batch $batch) use ($analysisId, $aiProvider, $deepThinkingEnabled, $promptTemplate, $aiModelId, $currentReduceLevel) {
                // Callback de sucesso: todos os batches concluídos
                Log::info('ReduceDocumentAnalysisJob: Batches do nível concluídos', [
                    'analysis_id' => $analysisId,
                    'reduce_level' => $currentReduceLevel,
                    'batch_id' => $batch->id,
                    'total_jobs' => $batch->totalJobs,
                    'failed_jobs' => $batch->failedJobs,
                ]);

                // Circuit breaker: aborta se taxa de falha exceder limite
                $threshold = config('analysis.circuit_breaker.failure_threshold', 0.25);
                $minJobs = config('analysis.circuit_breaker.min_jobs', 4);

                if ($batch->totalJobs >= $minJobs && $batch->totalJobs > 0) {
                    $failureRate = $batch->failedJobs / $batch->totalJobs;

                    if ($failureRate > $threshold) {
                        $failedPct = round($failureRate * 100);
                        $thresholdPct = round($threshold * 100);

                        Log::error('ReduceDocumentAnalysisJob: Circuit breaker ativado no REDUCE', [
                            'analysis_id' => $analysisId,
                            'reduce_level' => $currentReduceLevel,
                            'failed_jobs' => $batch->failedJobs,
                            'total_jobs' => $batch->totalJobs,
                            'failure_rate' => "{$failedPct}%",
                            'threshold' => "{$thresholdPct}%",
                        ]);

                        $documentAnalysis = DocumentAnalysis::find($analysisId);
                        if ($documentAnalysis) {
                            $documentAnalysis->markAsFailed(
                                "Consolidação abortada no nível {$currentReduceLevel}: {$batch->failedJobs} de {$batch->totalJobs} batches falharam ({$failedPct}%). Limite tolerado: {$thresholdPct}%."
                            );

                            NotificationService::error(
                                User::find($documentAnalysis->user_id),
                                'Consolidação Abortada',
                                "A consolidação do processo {$documentAnalysis->numero_processo} foi abortada no nível {$currentReduceLevel}: {$failedPct}% dos batches falharam (limite: {$thresholdPct}%)."
                            );
                        }

                        return;
                    }
                }

                // Dispara job para verificar próximo nível ou finalizar
                CheckReduceLevelCompletionJob::dispatch(
                    $analysisId,
                    $aiProvider,
                    $deepThinkingEnabled,
                    $promptTemplate,
                    $aiModelId,
                    $currentReduceLevel
                )->onQueue('analysis');
            })
            ->catch(function (Batch $batch, \Throwable $e) use ($analysisId, $currentReduceLevel) {
                Log::error('ReduceDocumentAnalysisJob: Erro em batch do nível', [
                    'analysis_id' => $analysisId,
                    'reduce_level' => $currentReduceLevel,
                    'batch_id' => $batch->id,
                    'error' => $e->getMessage(),
                ]);
            })
            ->progress(function (Batch $batch) use ($analysisId, $currentReduceLevel) {
                // Callback de progresso
                $documentAnalysis = DocumentAnalysis::find($analysisId);
                if ($documentAnalysis) {
                    $completed = $batch->totalJobs - $batch->pendingJobs - $batch->failedJobs;
                    $documentAnalysis->updateReduceProgress($currentReduceLevel, $completed, $batch->totalJobs);
                }
            })
            ->finally(function (Batch $batch) use ($analysisId, $currentReduceLevel) {
                Log::info('ReduceDocumentAnalysisJob: Batch de reduces finalizado', [
                    'analysis_id' => $analysisId,
                    'reduce_level' => $currentReduceLevel,
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

                        if ($documentAnalysis->status !== 'failed') {
                            $documentAnalysis->update([
                                'status' => 'failed',
                                'error_message' => "Consolidação abortada dinamicamente no nível {$currentReduceLevel}: Limite de falhas excedido ({$failedPct}% falharam). Limite tolerado: {$thresholdPct}%."
                            ]);
                        }

                        // Busca usuário para notificar
                        $user = User::find($documentAnalysis->user_id);
                        if ($user) {
                            try {
                                \App\Services\NotificationService::send(
                                    $user,
                                    'Análise Abortada (Circuit Breaker)',
                                    "A análise do processo {$documentAnalysis->numero_processo} foi interrompida na fase de consolidação devido à alta taxa de erros da IA ({$failedPct}% de falhas).",
                                    'danger'
                                );
                            } catch (\Exception $e) {}
                        }
                    }
                }
            })
            ->dispatch();

        Log::info('ReduceDocumentAnalysisJob: Batch de reduces disparado', [
            'analysis_id' => $documentAnalysis->id,
            'reduce_level' => $this->currentReduceLevel,
            'total_jobs' => count($reduceBatchJobs),
        ]);
    }

    /**
     * Gera a análise final consolidando todas as micro-análises restantes
     */
    private function generateFinalAnalysis(DocumentAnalysis $documentAnalysis, $microAnalyses): void
    {
        $startTime = microtime(true);

        // Atualiza status para análise final
        $documentAnalysis->startFinalAnalysis();

        Log::info('ReduceDocumentAnalysisJob: Gerando análise final', [
            'analysis_id' => $documentAnalysis->id,
            'micro_analyses_count' => $microAnalyses->count()
        ]);

        $aiService = AIServiceFactory::make($this->aiProvider);

        $langfuseContext = $documentAnalysis->ensureLangfuseContext();
        $aiService->setAnalysisContext([
            'user_id' => (string) $documentAnalysis->user_id,
            'session_id' => $langfuseContext['session_id'],
            'trace_id' => $langfuseContext['trace_id'],
            'entity' => 'document_analysis',
            'entity_id' => (string) $documentAnalysis->id,
        ]);

        $resolvedModelId = $this->resolveReduceModelId($documentAnalysis);

        // Define o modelo específico se configurado
        if (!empty($resolvedModelId)) {
            $aiService->setModel($resolvedModelId);
        } else {
            throw new \RuntimeException('ReduceDocumentAnalysisJob: nenhum modelo resolvido para geração final. Verifique o vínculo do prompt final_opinion com um modelo ativo.');
        }

        // Estende bastante o timeout para a consolidação final (pode ser muito demorado)
        $aiService->setTimeout(1800);

        $promptFromDb = AiPrompt::getDefaultForSystemAndType(1, AiPrompt::TYPE_FINAL_OPINION);
        $temperature = !is_null($promptFromDb?->temperature)
            ? (float) $promptFromDb->temperature
            : (float) config('services.openrouter.temperature_final', 0.4);

        // Parecer final precisa de mais tokens de saída e não deve resumir a entrada
        $aiService->setMaxTokens((int) config('services.openrouter.max_tokens_final', 16384));
        $aiService->setTemperature($temperature);
        $aiService->setInputCharLimit(null);

        $aggregatedEntities = $this->aggregateEntitiesFromMicros($microAnalyses);

        // Busca o prompt de parecer final do banco
        $basePromptContent = $promptFromDb?->content ?? $this->promptTemplate;

        // Verifica se o prompt é JSON com estrutura META (suporta injeção de upstream_inputs)
        if ($this->isJsonPromptWithMeta($basePromptContent)) {
            // Opção B: Injeta análises diretamente no JSON do prompt
            $upstreamInputs = $this->buildUpstreamInputsFromMicroAnalyses($microAnalyses);
            $prompt = $this->injectUpstreamInputs(
                $basePromptContent,
                $upstreamInputs,
                $aggregatedEntities
            );
            $consolidatedText = ''; // Conteúdo já injetado em META.upstream_inputs

            Log::info('ReduceDocumentAnalysisJob: Prompt JSON com upstream_inputs injetados', [
                'analysis_id' => $documentAnalysis->id,
                'total_inputs' => count($upstreamInputs),
            ]);
        } else {
            // Fluxo original para prompts em texto puro
            $prompt = str_replace(':basePrompt', $basePromptContent, config('prompts.final_opinion'));
            $consolidatedText = $this->buildBatchText($microAnalyses);
            $consolidatedText .= $this->buildEntitiesBlock($aggregatedEntities);
        }

        // Chama a IA para gerar análise final (rate limiting aplicado internamente)
        $finalAnalysis = $aiService->analyzeSingleDocument(
            $prompt,
            $consolidatedText,
            $this->deepThinkingEnabled
        );

        $processingTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        // Calcula tempo total (soma de todos os processamentos)
        $totalProcessingTime = $documentAnalysis->microAnalyses()
            ->whereNotNull('processing_time_ms')
            ->sum('processing_time_ms');
        $totalProcessingTime += $processingTimeMs;

        // Captura metadados da API
        $apiMetadata = $aiService->getLastAnalysisMetadata();

        // Salva arquivo de debug com a análise final
        $this->saveFinalAnalysisToFile($documentAnalysis, $finalAnalysis, $prompt, $consolidatedText, $microAnalyses, $apiMetadata);

        // Finaliza a análise
        $documentAnalysis->update([
            'status' => 'completed',
            'current_phase' => DocumentAnalysis::PHASE_COMPLETED,
            'ai_analysis' => $finalAnalysis,
            'analysis_ai_metadata' => $apiMetadata,
            'processing_time_ms' => $totalProcessingTime,
            'is_resumable' => false,
            'last_processed_at' => now(),
            'progress_message' => 'Análise concluída com sucesso!',
        ]);

        Log::info('ReduceDocumentAnalysisJob: Análise final concluída', [
            'analysis_id' => $documentAnalysis->id,
            'total_processing_time_ms' => $totalProcessingTime
        ]);

        // Notifica usuário
        $this->notifyUser($documentAnalysis, 'completed');
    }

    /**
     * Monta o texto consolidado de um batch de micro-análises
     */
    private function buildBatchText($microAnalyses): string
    {
        $text = "# ANÁLISES DOS DOCUMENTOS DO PROCESSO\n\n";

        // Coleta e ordena eventos da timeline de todas as micro-análises
        $timelineText = $this->buildOrderedTimeline($microAnalyses);
        if ($timelineText) {
            $text .= "## LINHA DO TEMPO CONSOLIDADA (ordenada por data)\n\n";
            $text .= $timelineText . "\n\n";
            $text .= "---\n\n";
        }

        $text .= "## ANÁLISES INDIVIDUAIS\n\n";

        foreach ($microAnalyses as $index => $micro) {
            $docNum = str_pad($index + 1, 2, '0', STR_PAD_LEFT);
            $fileName = mb_strtoupper($micro->descricao);

            $text .= "### INÍCIO DA ANÁLISE {$docNum} - ARQUIVO {$fileName} ###\n\n";
            // Remove o bloco JSON da timeline para não duplicar informação
            $analysisText = $this->removeTimelineJson($micro->micro_analysis);
            $text .= $analysisText . "\n\n";
            $text .= "### FIM DA ANÁLISE {$docNum} ###\n\n";
        }

        return $text;
    }

    /**
     * Constrói uma linha do tempo ordenada a partir de todas as micro-análises
     */
    private function buildOrderedTimeline($microAnalyses): ?string
    {
        $allEvents = [];

        foreach ($microAnalyses as $micro) {
            $events = $micro->timeline_events['eventos'] ?? [];
            $documentType = $micro->timeline_events['documento_tipo'] ?? $micro->descricao;

            foreach ($events as $event) {
                $event['fonte'] = $documentType;
                $event['documento_index'] = $micro->document_index;
                $allEvents[] = $event;
            }
        }

        if (empty($allEvents)) {
            return null;
        }

        // Ordena por data (eventos sem data vão para o final)
        usort($allEvents, function ($a, $b) {
            $dateA = $a['data'] ?? '9999-99-99';
            $dateB = $b['data'] ?? '9999-99-99';
            return strcmp($dateA, $dateB);
        });

        // Formata a timeline como texto estruturado
        $timeline = "";
        foreach ($allEvents as $event) {
            $data = $event['data'] ?? $event['data_original'] ?? 'Data não identificada';
            $tipo = $event['tipo'] ?? 'Evento';
            $descricao = $event['descricao'] ?? '';
            $fonte = $event['fonte'] ?? '';
            $relevancia = $event['relevancia'] ?? 'media';
            $valores = !empty($event['valores']) ? ' | Valores: ' . implode(', ', $event['valores']) : '';

            $marker = $relevancia === 'alta' ? '**[IMPORTANTE]**' : '';
            $timeline .= "- **{$data}** | {$tipo}: {$descricao}{$valores} {$marker}\n";
            $timeline .= "  _Fonte: {$fonte}_\n";
        }

        return $timeline;
    }

    /**
     * Remove o bloco JSON da timeline da análise para evitar duplicação
     */
    private function removeTimelineJson(string $analysis): string
    {
        return preg_replace('/<timeline_json>[\s\S]*?<\/timeline_json>/i', '', $analysis);
    }

    /**
     * Monta prompt para análise final
     * Busca o prompt padrão ativo de "Parecer Final" do banco para garantir consistência
     */
    private function buildFinalPrompt(): string
    {
        // Busca o prompt padrão ativo de "Parecer Final" do banco
        $promptFromDb = AiPrompt::getDefaultForSystemAndType(1, AiPrompt::TYPE_FINAL_OPINION);

        // Prioridade: 1º prompt do banco, 2º prompt passado como parâmetro
        $basePrompt = $promptFromDb?->content ?? $this->promptTemplate;

        return str_replace(':basePrompt', $basePrompt, config('prompts.final_opinion'));
    }

    /**
     * Notifica o usuário sobre o resultado
     */
    private function notifyUser(DocumentAnalysis $documentAnalysis, string $status, ?string $errorMessage = null): void
    {
        $user = User::find($documentAnalysis->user_id);
        if (!$user) {
            return;
        }

        try {
            if ($status === 'completed') {
                $totalDocs = $documentAnalysis->total_documents ?? 0;
                $timeSeconds = round(($documentAnalysis->processing_time_ms ?? 0) / 1000, 2);

                NotificationService::success(
                    $user,
                    'Análise Concluída',
                    "Análise de {$totalDocs} documento(s) do processo {$documentAnalysis->numero_processo} concluída com sucesso! Tempo total: {$timeSeconds}s"
                );

                // Envia e-mail com PDF se o usuário habilitou
                if ($user->wantsEmailFor('process_analysis')) {
                    try {
                        Mail::to($user)->send(new ProcessAnalysisCompleted($documentAnalysis, $user));
                        Log::info('ReduceDocumentAnalysisJob: E-mail de análise enviado', [
                            'user_id' => $user->id,
                            'analysis_id' => $documentAnalysis->id,
                        ]);
                    } catch (\Exception $emailException) {
                        Log::warning('ReduceDocumentAnalysisJob: Falha ao enviar e-mail', [
                            'user_id' => $user->id,
                            'error' => $emailException->getMessage(),
                        ]);
                    }
                }
            } else {
                NotificationService::error(
                    $user,
                    'Análise Falhou',
                    "Erro na análise do processo {$documentAnalysis->numero_processo}: " . ($errorMessage ?? 'Erro desconhecido')
                );
            }
        } catch (\Exception $e) {
            Log::warning('ReduceDocumentAnalysisJob: Erro ao notificar usuário', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Calcula quantos níveis de REDUCE serão necessários
     */
    private function calculateTotalLevels(int $totalItems): int
    {
        if ($totalItems <= config('analysis.reduce.batch_size', 10)) {
            return 1;
        }

        $levels = 1;
        $items = $totalItems;

        while ($items > config('analysis.reduce.batch_size', 10)) {
            $items = (int) ceil($items / config('analysis.reduce.batch_size', 10));
            $levels++;
        }

        return min($levels, config('analysis.reduce.max_levels', 5));
    }

    /**
     * Notifica o início da fase REDUCE
     */
    private function notifyReduceStart(DocumentAnalysis $documentAnalysis, int $microAnalysesCount): void
    {
        $user = User::find($documentAnalysis->user_id);
        if (!$user) {
            return;
        }

        try {
            $providerName = 'OpenRouter';

            NotificationService::info(
                $user,
                'Fase 2/2: Consolidação',
                "Análise individual concluída! A {$providerName} está consolidando {$microAnalysesCount} análises em paralelo para gerar a visão completa do processo."
            );
        } catch (\Exception $e) {
            Log::warning('ReduceDocumentAnalysisJob: Erro ao notificar início do REDUCE', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Salva a análise final em arquivo para debug/inspeção
     */
    private function saveFinalAnalysisToFile(
        DocumentAnalysis $documentAnalysis,
        string $finalAnalysis,
        string $prompt,
        string $consolidatedText,
        $microAnalyses,
        array $apiMetadata = []
    ): void {
        // Verifica se debug de arquivos está ativo
        if (!Setting::isDebugAnalysisFilesEnabled()) {
            return;
        }
        try {
            $numeroProcesso = preg_replace('/[^0-9]/', '', $documentAnalysis->numero_processo ?? 'unknown');
            $analysisId = $documentAnalysis->id;
            $timestamp = now()->format('Y-m-d_H-i-s');

            $baseDir = "analises-debug/{$numeroProcesso}/analysis_{$analysisId}";

            $fileName = "PARECER_FINAL_{$timestamp}.md";

            // Lista os IDs das micro-análises usadas
            $microIds = $microAnalyses->pluck('id')->toArray();
            $microIdsJson = $this->formatJsonForDebug($microIds);

            // Extrai metadados da API (null coalescing não funciona em heredoc)
            $metaModeloApi = $apiMetadata['model'] ?? 'N/A';
            $metaTokensPrompt = $apiMetadata['total_prompt_tokens'] ?? 'N/A';
            $metaTokensCompletion = $apiMetadata['total_completion_tokens'] ?? 'N/A';
            $metaTokensReasoning = $apiMetadata['total_reasoning_tokens'] ?? 0;
            $metaTokensTotal = $apiMetadata['total_tokens'] ?? 'N/A';
            $metaApiCalls = $apiMetadata['api_calls_count'] ?? 1;

            $content = <<<MD
# PARECER FINAL - Processo {$documentAnalysis->numero_processo}

## Metadados

| Campo | Valor |
|-------|-------|
| **ID da Análise** | {$analysisId} |
| **Número do Processo** | {$documentAnalysis->numero_processo} |
| **Classe Processual** | {$documentAnalysis->classe_processual} |
| **Assuntos** | {$documentAnalysis->assuntos} |
| **Total de Documentos** | {$documentAnalysis->total_documents} |
| **Micro-Análises Consolidadas** | {$microAnalyses->count()} |
| **Reduce Level** | {$this->currentReduceLevel} |
| **Provider** | {$this->aiProvider} |
| **Model ID** | {$this->aiModelId} |
| **Deep Thinking** | {$this->deepThinkingEnabled} |
| **Data/Hora** | {$timestamp} |
| **Modelo Resposta API** | {$metaModeloApi} |
| **Tokens Enviados (Prompt)** | {$metaTokensPrompt} |
| **Tokens Recebidos (Completion)** | {$metaTokensCompletion} |
| **Tokens de Raciocínio** | {$metaTokensReasoning} |
| **Total de Tokens** | {$metaTokensTotal} |
| **Chamadas à API** | {$metaApiCalls} |

## IDs das Micro-Análises Consolidadas

```json
{$microIdsJson}
```

---

## Prompt Enviado à IA (Parecer Final)

```
{$prompt}
```

---

## Texto Consolidado Enviado à IA (entrada completa)

{$consolidatedText}

---

## RESULTADO: PARECER FINAL

{$finalAnalysis}

MD;

            Storage::disk('local')->put("{$baseDir}/{$fileName}", $content);

            Log::info('ReduceDocumentAnalysisJob: Arquivo de parecer final salvo', [
                'path' => "{$baseDir}/{$fileName}",
                'analysis_id' => $analysisId
            ]);

        } catch (\Exception $e) {
            Log::warning('ReduceDocumentAnalysisJob: Falha ao salvar arquivo de parecer final', [
                'analysis_id' => $documentAnalysis->id ?? null,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Resolve o modelo do REDUCE com fallback seguro para evitar model vazio.
     */
    private function resolveReduceModelId(DocumentAnalysis $documentAnalysis): ?string
    {
        if (!empty($this->aiModelId)) {
            return $this->aiModelId;
        }

        $jobParams = is_array($documentAnalysis->job_parameters) ? $documentAnalysis->job_parameters : [];

        $modelFromJob = $jobParams['aiModelId'] ?? $jobParams['ai_model_id'] ?? null;
        if (!empty($modelFromJob)) {
            return $modelFromJob;
        }

        $promptFromDb = AiPrompt::getDefaultForSystemAndType(1, AiPrompt::TYPE_FINAL_OPINION);

        return $promptFromDb?->aiModel?->model_id;
    }

    /**
     * Agrega entidades "duras" de todas as micro-análises para injeção no parecer final.
     *
     * @param \Illuminate\Support\Collection $microAnalyses
     */
    private function aggregateEntitiesFromMicros($microAnalyses): array
    {
        $partes = [];
        $valores = [];
        $pontos = [];

        foreach ($microAnalyses as $micro) {
            $entities = $micro->getEntities();
            array_push($partes, ...$entities['partes_mencionadas']);
            array_push($valores, ...$entities['valores_monetarios']);
            array_push($pontos, ...$entities['pontos_chave']);
        }

        return [
            'partes_mencionadas' => array_values(array_unique($partes)),
            'valores_monetarios' => array_values(array_unique($valores)),
            'pontos_chave' => array_values(array_unique($pontos)),
        ];
    }

    /**
     * Monta bloco markdown de entidades para injeção no texto consolidado.
     */
    private function buildEntitiesBlock(array $entities): string
    {
        $hasAny = !empty($entities['partes_mencionadas'])
            || !empty($entities['valores_monetarios'])
            || !empty($entities['pontos_chave']);

        if (!$hasAny) {
            return '';
        }

        $block = "\n\n---\n\n## ENTIDADES EXTRAÍDAS (dados consolidados pelo sistema - NÃO omitir)\n\n";

        if (!empty($entities['partes_mencionadas'])) {
            $block .= "### Partes Mencionadas\n";
            foreach ($entities['partes_mencionadas'] as $parte) {
                $block .= "- {$parte}\n";
            }
            $block .= "\n";
        }

        if (!empty($entities['valores_monetarios'])) {
            $block .= "### Valores Monetários\n";
            foreach ($entities['valores_monetarios'] as $valor) {
                $block .= "- {$valor}\n";
            }
            $block .= "\n";
        }

        if (!empty($entities['pontos_chave'])) {
            $block .= "### Pontos-Chave\n";
            foreach ($entities['pontos_chave'] as $ponto) {
                $block .= "- {$ponto}\n";
            }
            $block .= "\n";
        }

        return $block;
    }

}
