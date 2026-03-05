<?php

namespace App\Jobs;

use App\Models\DocumentMicroAnalysis;
use App\Models\DocumentAnalysis;
use App\Models\AiPrompt;
use App\Models\Setting;
use App\Services\AIServiceFactory;
use App\Traits\HandlesJsonOutput;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job para processar um batch individual da fase REDUCE.
 * Utiliza o trait Batchable para processamento paralelo via Bus::batch().
 */
class ReduceBatchJob implements ShouldQueue
{
    use Queueable, Batchable, HandlesJsonOutput;

    public int $timeout;
    public int $tries;
    public int $backoff;

    public function __construct(
        public int $documentAnalysisId,
        public array $microAnalysisIds, // IDs das micro-análises a consolidar
        public int $batchIndex,
        public int $reduceLevel,
        public string $aiProvider,
        public bool $deepThinkingEnabled,
        public ?string $aiModelId = null
    ) {
        $this->timeout = config('analysis.jobs.reduce_batch.timeout', 600);
        $this->tries = config('analysis.jobs.reduce_batch.tries', 3);
        $this->backoff = config('analysis.jobs.reduce_batch.backoff', 60);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $batch = $this->batch();

        // Verifica se o batch foi cancelado
        if ($batch?->cancelled()) {
            Log::info('ReduceBatchJob: Batch cancelado', [
                'analysis_id' => $this->documentAnalysisId,
                'batch_index' => $this->batchIndex,
            ]);
            return;
        }

        // Validação dinâmica do Circuit Breaker no início da execução
        if ($batch) {
            $threshold = config('analysis.circuit_breaker.failure_threshold', 0.25);
            $minJobs = config('analysis.circuit_breaker.min_jobs', 4);

            if ($batch->totalJobs >= $minJobs && $batch->totalJobs > 0) {
                $failureRate = $batch->failedJobs / $batch->totalJobs;
                if ($failureRate > $threshold) {
                    Log::warning('ReduceBatchJob: Circuit breaker acionado dinamicamente, cancelando lote.', [
                        'analysis_id' => $this->documentAnalysisId,
                        'batch_index' => $this->batchIndex,
                        'failed_jobs' => $batch->failedJobs,
                        'total_jobs' => $batch->totalJobs,
                        'failure_rate' => ($failureRate * 100) . '%',
                    ]);
                    $batch->cancel();
                    return;
                }
            }
        }

        $startTime = microtime(true);

        try {
            $documentAnalysis = DocumentAnalysis::find($this->documentAnalysisId);

            if (!$documentAnalysis) {
                Log::error('ReduceBatchJob: DocumentAnalysis não encontrada', [
                    'id' => $this->documentAnalysisId
                ]);
                return;
            }

            // Verifica se foi cancelada
            if ($documentAnalysis->status === 'cancelled') {
                Log::info('ReduceBatchJob: Análise cancelada', [
                    'id' => $this->documentAnalysisId
                ]);
                return;
            }

            // Busca as micro-análises a consolidar
            $microAnalyses = DocumentMicroAnalysis::whereIn('id', $this->microAnalysisIds)
                ->where('status', 'completed')
                ->orderBy('document_index')
                ->get();

            if ($microAnalyses->isEmpty()) {
                Log::warning('ReduceBatchJob: Nenhuma micro-análise encontrada para consolidar', [
                    'analysis_id' => $this->documentAnalysisId,
                    'batch_index' => $this->batchIndex,
                    'expected_ids' => $this->microAnalysisIds,
                ]);
                return;
            }

            Log::info('ReduceBatchJob: Iniciando consolidação de batch', [
                'analysis_id' => $this->documentAnalysisId,
                'batch_index' => $this->batchIndex,
                'reduce_level' => $this->reduceLevel,
                'micro_analyses_count' => $microAnalyses->count(),
            ]);

            // Agrega entidades "duras" (partes, valores, pontos-chave) das micro-análises filhas
            $aggregatedEntities = $this->aggregateEntities($microAnalyses);

            // Verifica se já existe registro para este batch (evita duplicação em retry)
            $reduceMicro = DocumentMicroAnalysis::where('document_analysis_id', $this->documentAnalysisId)
                ->where('document_index', $this->batchIndex)
                ->where('reduce_level', $this->reduceLevel)
                ->first();

            if ($reduceMicro) {
                // Se já está completo, pula processamento
                if ($reduceMicro->isCompleted()) {
                    Log::info('ReduceBatchJob: Batch já processado, pulando', [
                        'analysis_id' => $this->documentAnalysisId,
                        'batch_index' => $this->batchIndex,
                        'reduce_micro_id' => $reduceMicro->id,
                    ]);
                    return;
                }

                // Se existe mas não está completo, reutiliza o registro
                Log::info('ReduceBatchJob: Retomando batch existente', [
                    'reduce_micro_id' => $reduceMicro->id,
                    'status' => $reduceMicro->status,
                ]);
                $reduceMicro->markAsProcessing();
                $reduceMicro->update(['aggregated_entities' => $aggregatedEntities]);
            } else {
                // Cria novo registro para o resultado do reduce
                $reduceMicro = DocumentMicroAnalysis::create([
                    'document_analysis_id' => $this->documentAnalysisId,
                    'document_index' => $this->batchIndex,
                    'descricao' => "Consolidação nível {$this->reduceLevel} - Batch {$this->batchIndex}",
                    'reduce_level' => $this->reduceLevel,
                    'parent_ids' => $this->microAnalysisIds,
                    'status' => 'processing',
                    'aggregated_entities' => $aggregatedEntities,
                ]);
            }

            // Obtém o serviço de IA
            $aiService = AIServiceFactory::make($this->aiProvider);

            $resolvedModelId = $this->resolveReduceModelId($documentAnalysis);

            // Define o modelo específico se configurado
            if (!empty($resolvedModelId)) {
                $aiService->setModel($resolvedModelId);
            } else {
                throw new \RuntimeException('ReduceBatchJob: nenhum modelo resolvido para consolidação do batch. Verifique o vínculo do prompt final_opinion com um modelo ativo.');
            }

            // Aumenta o tempo limite tolerado, consolidar lotes leva mais tempo da API
            $aiService->setTimeout(1200);

            // REDUCE precisa de mais tokens de saída e não deve resumir a entrada
            $aiService->setMaxTokens((int) config('services.openrouter.max_tokens_reduce', 16384));
            $aiService->setInputCharLimit(null);

            // Monta o texto consolidado do batch
            $consolidatedText = $this->buildBatchText($microAnalyses);

            // Monta prompt de consolidação (rate limiting é aplicado internamente pelo AI service)
            $prompt = $this->buildReducePrompt($microAnalyses->count());

            // Chama a IA para consolidar
            $result = $aiService->analyzeSingleDocument(
                $prompt,
                $consolidatedText,
                $this->deepThinkingEnabled
            );

            $processingTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $reduceMicro->markAsCompleted(
                $result,
                $this->estimateTokenCount($result),
                $processingTimeMs
            );

            // Captura metadados da API
            $apiMetadata = $aiService->getLastAnalysisMetadata();

            // Salva arquivo de debug com resultado da consolidação
            $this->saveReduceToFile($documentAnalysis, $reduceMicro, $result, $prompt, $consolidatedText, $apiMetadata);

            Log::info('ReduceBatchJob: Batch consolidado com sucesso', [
                'analysis_id' => $this->documentAnalysisId,
                'batch_index' => $this->batchIndex,
                'reduce_micro_id' => $reduceMicro->id,
                'processing_time_ms' => $processingTimeMs,
            ]);

        } catch (\Exception $e) {
            Log::error('ReduceBatchJob: Erro na consolidação', [
                'analysis_id' => $this->documentAnalysisId,
                'batch_index' => $this->batchIndex,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if (isset($reduceMicro)) {
                $reduceMicro->markAsFailed($e->getMessage());
            }

            throw $e;
        }
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
     * Monta prompt para consolidação intermediária (via config/prompts.php)
     */
    private function buildReducePrompt(int $documentCount): string
    {
        return str_replace(':documentCount', (string) $documentCount, config('prompts.reduce_consolidation'));
    }


    /**
     * Salva o resultado da consolidação (REDUCE) em arquivo para debug/inspeção
     */
    private function saveReduceToFile(
        DocumentAnalysis $documentAnalysis,
        DocumentMicroAnalysis $reduceMicro,
        string $result,
        string $prompt,
        string $consolidatedText,
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

            // Cria diretório para reduces
            $baseDir = "analises-debug/{$numeroProcesso}/analysis_{$analysisId}/reduces";

            $fileName = "reduce_nivel_{$this->reduceLevel}_batch_{$this->batchIndex}_{$timestamp}.md";

            $parentIdsJson = $this->formatJsonForDebug($this->microAnalysisIds);

            // Extrai metadados da API (null coalescing não funciona em heredoc)
            $metaModeloApi = $apiMetadata['model'] ?? 'N/A';
            $metaTokensPrompt = $apiMetadata['total_prompt_tokens'] ?? 'N/A';
            $metaTokensCompletion = $apiMetadata['total_completion_tokens'] ?? 'N/A';
            $metaTokensReasoning = $apiMetadata['total_reasoning_tokens'] ?? 0;
            $metaTokensTotal = $apiMetadata['total_tokens'] ?? 'N/A';
            $metaApiCalls = $apiMetadata['api_calls_count'] ?? 1;

            $content = <<<MD
# Consolidação REDUCE - Nível {$this->reduceLevel} - Batch {$this->batchIndex}

## Metadados

| Campo | Valor |
|-------|-------|
| **ID da Micro-Análise (Reduce)** | {$reduceMicro->id} |
| **ID da Análise Principal** | {$analysisId} |
| **Número do Processo** | {$documentAnalysis->numero_processo} |
| **Reduce Level** | {$this->reduceLevel} |
| **Batch Index** | {$this->batchIndex} |
| **Micro-Análises Consolidadas** | {$this->formatCount(count($this->microAnalysisIds))} |
| **Token Count** | {$reduceMicro->token_count} |
| **Processing Time (ms)** | {$reduceMicro->processing_time_ms} |
| **Provider** | {$this->aiProvider} |
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
{$parentIdsJson}
```

---

## Prompt Enviado à IA

```
{$prompt}
```

---

## Texto Consolidado Enviado à IA (entrada)

{$consolidatedText}

---

## Resultado da Consolidação (micro_analysis)

{$result}

MD;

            Storage::disk('local')->put("{$baseDir}/{$fileName}", $content);

            Log::info('ReduceBatchJob: Arquivo de debug salvo', [
                'path' => "{$baseDir}/{$fileName}",
                'reduce_micro_id' => $reduceMicro->id
            ]);

        } catch (\Exception $e) {
            Log::warning('ReduceBatchJob: Falha ao salvar arquivo de debug', [
                'reduce_micro_id' => $reduceMicro->id ?? null,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Agrega entidades "duras" de todas as micro-análises filhas.
     * Usa array_unique para deduplicar e array_values para reindexar.
     *
     * @param \Illuminate\Support\Collection $microAnalyses
     */
    private function aggregateEntities($microAnalyses): array
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
     * Formata contagem para exibição
     */
    private function formatCount(int $count): string
    {
        return "{$count} documento(s)";
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

}
