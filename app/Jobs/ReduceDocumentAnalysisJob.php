<?php

namespace App\Jobs;

use App\Models\AiPrompt;
use App\Models\DocumentMicroAnalysis;
use App\Models\DocumentAnalysis;
use App\Models\Setting;
use App\Models\User;
use App\Services\AIServiceFactory;
use App\Services\NotificationService;
use App\Services\RateLimiterService;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Filament\Notifications\Notification as FilamentNotification;

/**
 * Job responsável pela fase REDUCE do map-reduce.
 * Consolida micro-análises em análises maiores até gerar a análise final.
 *
 * Arquitetura de Processamento Paralelo:
 * - Batches são processados em paralelo via Bus::batch() + ReduceBatchJob
 * - Após todos os batches de um nível, dispara o próximo nível ou gera análise final
 */
class ReduceDocumentAnalysisJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600; // 10 minutos para reduce
    public int $tries = 3;
    public int $backoff = 60;

    // Configuração do reduce hierárquico
    private const BATCH_SIZE = 10; // Quantas micro-análises consolidar por vez
    private const MAX_REDUCE_LEVELS = 5; // Limite de níveis de reduce

    public function __construct(
        public int $documentAnalysisId,
        public string $aiProvider,
        public bool $deepThinkingEnabled,
        public string $promptTemplate,
        public ?string $aiModelId = null, // ID do modelo específico (ex: gemini-2.5-flash)
        public int $currentReduceLevel = 1
    ) {
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
            $totalBatches = (int) ceil($totalMicroAnalyses / self::BATCH_SIZE);

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
            if ($totalMicroAnalyses <= self::BATCH_SIZE) {
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
        $batches = $microAnalyses->chunk(self::BATCH_SIZE);
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

        // Define o modelo específico se configurado
        if ($this->aiModelId) {
            $aiService->setModel($this->aiModelId);
        }

        // Monta o texto consolidado
        $consolidatedText = $this->buildBatchText($microAnalyses);

        // Monta prompt final
        $prompt = $this->buildFinalPrompt();

        // Aplica rate limiting
        RateLimiterService::apply($this->aiProvider);

        // Chama a IA para gerar análise final
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

        // Salva arquivo de debug com a análise final
        $this->saveFinalAnalysisToFile($documentAnalysis, $finalAnalysis, $prompt, $consolidatedText, $microAnalyses);

        // Finaliza a análise
        $documentAnalysis->update([
            'status' => 'completed',
            'current_phase' => DocumentAnalysis::PHASE_COMPLETED,
            'ai_analysis' => $finalAnalysis,
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
        // Isso garante que sempre use o prompt mais atual configurado
        $promptFromDb = AiPrompt::getDefaultForSystemAndType(1, AiPrompt::TYPE_FINAL_OPINION);

        // Prioridade: 1º prompt do banco, 2º prompt passado como parâmetro
        $basePrompt = $promptFromDb?->content ?? $this->promptTemplate;

        return <<<PROMPT
# ANÁLISE FINAL DO PROCESSO

Você recebeu análises consolidadas de todos os documentos do processo judicial.

Com base nessas informações, forneça a análise solicitada pelo usuário:

---

{$basePrompt}

---

## INSTRUÇÕES ADICIONAIS

1. Considere TODOS os documentos que foram analisados
2. Mantenha a perspectiva cronológica e causal dos eventos
3. Fundamente suas conclusões nos documentos analisados
4. Seja objetivo e direto nas conclusões
5. Use markdown para estruturar a resposta

Responda com a análise completa conforme solicitado.
PROMPT;
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

                FilamentNotification::make()
                    ->title('Análise Concluída')
                    ->body("Análise de {$totalDocs} documento(s) do processo {$documentAnalysis->numero_processo} concluída com sucesso! Tempo total: {$timeSeconds}s")
                    ->status('success')
                    ->sendToDatabase($user);
            } else {
                FilamentNotification::make()
                    ->title('Análise Falhou')
                    ->body("Erro na análise do processo {$documentAnalysis->numero_processo}: " . ($errorMessage ?? 'Erro desconhecido'))
                    ->status('danger')
                    ->sendToDatabase($user);
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
        if ($totalItems <= self::BATCH_SIZE) {
            return 1;
        }

        $levels = 1;
        $items = $totalItems;

        while ($items > self::BATCH_SIZE) {
            $items = (int) ceil($items / self::BATCH_SIZE);
            $levels++;
        }

        return min($levels, self::MAX_REDUCE_LEVELS);
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
            $providerName = match ($this->aiProvider) {
                'gemini' => 'Google Gemini',
                'deepseek' => 'DeepSeek',
                'openai' => 'OpenAI',
                default => 'IA'
            };

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
        $microAnalyses
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
            $microIdsJson = json_encode($microIds, JSON_PRETTY_PRINT);

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

{$this->truncateTextFinal($consolidatedText, 20000)}

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
     * Trunca texto para exibição
     */
    private function truncateTextFinal(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength) . "\n\n... [TRUNCADO - Total: " . mb_strlen($text) . " caracteres]";
    }
}
