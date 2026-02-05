<?php

namespace App\Jobs;

use App\Models\AiPrompt;
use App\Models\DocumentAnalysis;
use App\Models\User;
use App\Services\AIServiceFactory;
use App\Services\NotificationService;
use App\Services\RateLimiterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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
    ) {
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

                NotificationService::success(
                    $user,
                    'Análise Concluída',
                    "Análise de {$totalDocs} documento(s) do processo {$documentAnalysis->numero_processo} concluída com sucesso! Tempo total: {$timeSeconds}s"
                );
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

}
