<?php

namespace App\Jobs;

use App\Models\DocumentAnalysis;
use App\Models\User;
use App\Contracts\AIProviderInterface;
use App\Services\GeminiService;
use App\Services\DeepSeekService;
use App\Services\OpenAIService;
use App\Services\RateLimiterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Filament\Notifications\Notification as FilamentNotification;

/**
 * Job responsável por verificar se um nível de REDUCE foi concluído
 * e decidir se precisa de mais níveis ou pode gerar a análise final.
 */
class CheckReduceLevelCompletionJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries = 3;
    public int $backoff = 30;

    private const BATCH_SIZE = 10;
    private const MAX_REDUCE_LEVELS = 5;

    public function __construct(
        public int $analysisId,
        public string $aiProvider,
        public bool $deepThinkingEnabled,
        public string $promptTemplate,
        public ?string $aiModelId,
        public int $completedReduceLevel
    ) {}

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
            if ($completedCount > self::BATCH_SIZE && $this->completedReduceLevel < self::MAX_REDUCE_LEVELS) {
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
            Log::warning('CheckReduceLevelCompletionJob: Erro ao notificar usuário', [
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
}
