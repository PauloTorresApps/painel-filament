<?php

namespace App\Jobs;

use App\Models\DocumentMicroAnalysis;
use App\Models\DocumentAnalysis;
use App\Models\User;
use App\Contracts\AIProviderInterface;
use App\Services\GeminiService;
use App\Services\DeepSeekService;
use App\Services\OpenAIService;
use App\Services\RateLimiterService;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
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
    ) {}

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

        $aiService = $this->getAIService($this->aiProvider);

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

        foreach ($microAnalyses as $index => $micro) {
            $docNum = $index + 1;
            $text .= "---\n\n";
            $text .= "## DOCUMENTO {$docNum}: {$micro->descricao}\n\n";
            $text .= $micro->micro_analysis . "\n\n";
        }

        return $text;
    }

    /**
     * Monta prompt para análise final
     */
    private function buildFinalPrompt(): string
    {
        $basePrompt = $this->promptTemplate;

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
     * Retorna o serviço de IA
     */
    private function getAIService(string $provider): AIProviderInterface
    {
        return match ($provider) {
            'deepseek' => new DeepSeekService(),
            'gemini' => new GeminiService(),
            'openai' => new OpenAIService(),
            default => new GeminiService(),
        };
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

            FilamentNotification::make()
                ->title('Fase 2/2: Consolidação')
                ->body("Análise individual concluída! A {$providerName} está consolidando {$microAnalysesCount} análises em paralelo para gerar a visão completa do processo.")
                ->status('info')
                ->sendToDatabase($user);
        } catch (\Exception $e) {
            Log::warning('ReduceDocumentAnalysisJob: Erro ao notificar início do REDUCE', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
