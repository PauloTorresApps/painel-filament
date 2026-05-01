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
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Job responsável por verificar se um nível de REDUCE foi concluído
 * e decidir se precisa de mais níveis ou pode gerar a análise final.
 */
class CheckReduceLevelCompletionJob implements ShouldQueue
{
    use Queueable, HandlesJsonOutput, InjectsUpstreamInputs;

    public int $timeout;
    public int $tries;
    public int $backoff;

    public function __construct(
        public int $analysisId,
        public string $aiProvider,
        public bool $deepThinkingEnabled,
        public string $promptTemplate,
        public ?string $aiModelId,
        public int $completedReduceLevel
    ) {
        $this->timeout = config('analysis.jobs.check_reduce_completion.timeout', 600);
        $this->tries = config('analysis.jobs.check_reduce_completion.tries', 3);
        $this->backoff = config('analysis.jobs.check_reduce_completion.backoff', 30);
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
            $documentAnalysis = DocumentAnalysis::find($this->analysisId);

            if (!$documentAnalysis) {
                Log::error('CheckReduceLevelCompletionJob: DocumentAnalysis não encontrada', [
                    'id' => $this->analysisId
                ]);
                return;
            }

            // Verifica se foi cancelada
            if ($documentAnalysis->status === 'cancelled') {
                Log::info('CheckReduceLevelCompletionJob: Análise cancelada', [
                    'id' => $this->analysisId
                ]);
                return;
            }

            // Busca micro-análises completadas no nível atual
            $completedReduces = $documentAnalysis->microAnalyses()
                ->reduceLevel($this->completedReduceLevel)
                ->completed()
                ->orderBy('document_index')
                ->get();

            $completedCount = $completedReduces->count();

            Log::info('CheckReduceLevelCompletionJob: Verificando conclusão do nível', [
                'analysis_id' => $this->analysisId,
                'reduce_level' => $this->completedReduceLevel,
                'completed_count' => $completedCount,
            ]);

            if ($completedCount === 0) {
                // Todos falharam neste nível
                $documentAnalysis->update([
                    'status' => 'failed',
                    'error_message' => "Falha na consolidação do nível {$this->completedReduceLevel}"
                ]);

                $this->notifyUser($documentAnalysis, 'failed', "Falha na consolidação do nível {$this->completedReduceLevel}");
                return;
            }

            // Se há mais de BATCH_SIZE resultados e não atingiu o limite de níveis, precisa de mais um nível
            if ($completedCount > config('analysis.reduce.batch_size', 10) && $this->completedReduceLevel < config('analysis.reduce.max_levels', 5)) {
                Log::info('CheckReduceLevelCompletionJob: Disparando próximo nível de reduce', [
                    'analysis_id' => $this->analysisId,
                    'next_level' => $this->completedReduceLevel + 1,
                    'micro_analyses_to_reduce' => $completedCount
                ]);

                // Dispara próximo nível de reduce
                ReduceDocumentAnalysisJob::dispatch(
                    $this->analysisId,
                    $this->aiProvider,
                    $this->deepThinkingEnabled,
                    $this->promptTemplate,
                    $this->aiModelId,
                    $this->completedReduceLevel + 1
                )->onQueue('analysis');

                return;
            }

            // Pode gerar análise final
            Log::info('CheckReduceLevelCompletionJob: Gerando análise final', [
                'analysis_id' => $this->analysisId,
                'micro_analyses_count' => $completedCount
            ]);

            $this->generateFinalAnalysis($documentAnalysis, $completedReduces);

        } catch (\Exception $e) {
            Log::error('CheckReduceLevelCompletionJob: Erro', [
                'analysis_id' => $this->analysisId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $documentAnalysis = DocumentAnalysis::find($this->analysisId);
            if ($documentAnalysis) {
                $documentAnalysis->update([
                    'status' => 'failed',
                    'error_message' => 'Erro ao verificar conclusão do reduce: ' . $e->getMessage()
                ]);
            }

            throw $e;
        }
    }

    /**
     * Gera a análise final consolidando todas as micro-análises restantes
     */
    private function generateFinalAnalysis(DocumentAnalysis $documentAnalysis, $microAnalyses): void
    {
        $startTime = microtime(true);

        // Atualiza status para análise final
        $documentAnalysis->startFinalAnalysis();

        $aiService = AIServiceFactory::make($this->aiProvider);

        $langfuseContext = $documentAnalysis->ensureLangfuseContext();
        $aiService->setAnalysisContext([
            'user_id' => (string) $documentAnalysis->user_id,
            'session_id' => $langfuseContext['session_id'],
            'trace_id' => $langfuseContext['trace_id'],
            'entity' => 'document_analysis',
            'entity_id' => (string) $documentAnalysis->id,
        ]);

        // Define o modelo específico se configurado
        if ($this->aiModelId) {
            $aiService->setModel($this->aiModelId);
        }

        $promptFromDb = AiPrompt::getDefaultForSystemAndType(1, AiPrompt::TYPE_FINAL_OPINION);
        $temperature = !is_null($promptFromDb?->temperature)
            ? (float) $promptFromDb->temperature
            : (float) config('services.openrouter.temperature_final', 0.4);

        // Parecer final precisa de mais tokens de saída
        $aiService->setMaxTokens((int) config('services.openrouter.max_tokens_final', 16384));
        $aiService->setTemperature($temperature);

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

            Log::info('CheckReduceLevelCompletionJob: Prompt JSON com upstream_inputs injetados', [
                'analysis_id' => $documentAnalysis->id,
                'total_inputs' => count($upstreamInputs),
            ]);
        } else {
            // Fluxo original para prompts em texto puro
            $prompt = $this->buildFinalPrompt();
            $consolidatedText = $this->buildBatchText($microAnalyses);
            $consolidatedText .= $this->buildEntitiesBlock($aggregatedEntities);
        }

        // Chama a IA para gerar análise final
        // Usa web search se habilitado, para referenciar legislação/jurisprudência atualizada
        $finalAnalysis = $aiService->analyzeWithWebSearch(
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

        Log::info('CheckReduceLevelCompletionJob: Análise final concluída', [
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

        $text .= "## ANÁLISES CONSOLIDADAS\n\n";

        foreach ($microAnalyses as $index => $micro) {
            $docNum = str_pad($index + 1, 2, '0', STR_PAD_LEFT);
            $fileName = mb_strtoupper($micro->descricao);

            $text .= "### INÍCIO DA ANÁLISE {$docNum} - {$fileName} ###\n\n";
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

        $wrapperTemplate = AiPrompt::resolvePromptContent(
            1,
            AiPrompt::TYPE_FINAL_OPINION_WRAPPER
        );

        return str_replace(':basePrompt', $basePrompt, $wrapperTemplate);
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
                        Log::info('CheckReduceLevelCompletionJob: E-mail de análise enviado', [
                            'user_id' => $user->id,
                            'analysis_id' => $documentAnalysis->id,
                        ]);
                    } catch (\Exception $emailException) {
                        Log::warning('CheckReduceLevelCompletionJob: Falha ao enviar e-mail', [
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
            Log::warning('CheckReduceLevelCompletionJob: Erro ao notificar usuário', [
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
| **Reduce Level Concluído** | {$this->completedReduceLevel} |
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

            Log::info('CheckReduceLevelCompletionJob: Arquivo de parecer final salvo', [
                'path' => "{$baseDir}/{$fileName}",
                'analysis_id' => $analysisId
            ]);

        } catch (\Exception $e) {
            Log::warning('CheckReduceLevelCompletionJob: Falha ao salvar arquivo de parecer final', [
                'analysis_id' => $documentAnalysis->id ?? null,
                'error' => $e->getMessage()
            ]);
        }
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
