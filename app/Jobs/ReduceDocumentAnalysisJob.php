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
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Filament\Notifications\Notification as FilamentNotification;

/**
 * Job responsável pela fase REDUCE do map-reduce.
 * Consolida micro-análises em análises maiores até gerar a análise final.
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
        public int $currentReduceLevel = 1
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);

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

            Log::info('ReduceDocumentAnalysisJob: Micro-análises a consolidar', [
                'analysis_id' => $this->documentAnalysisId,
                'count' => $totalMicroAnalyses,
                'previous_level' => $previousLevel
            ]);

            // Se há apenas uma micro-análise ou poucas o suficiente, gera análise final
            if ($totalMicroAnalyses <= self::BATCH_SIZE) {
                $this->generateFinalAnalysis($documentAnalysis, $microAnalyses, $startTime);
                return;
            }

            // Caso contrário, faz reduce hierárquico em batches
            $this->performHierarchicalReduce($documentAnalysis, $microAnalyses);

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
     * Executa reduce hierárquico em batches
     */
    private function performHierarchicalReduce(DocumentAnalysis $documentAnalysis, $microAnalyses): void
    {
        $batches = $microAnalyses->chunk(self::BATCH_SIZE);
        $aiService = $this->getAIService($this->aiProvider);
        $batchIndex = 0;

        Log::info('ReduceDocumentAnalysisJob: Executando reduce hierárquico', [
            'analysis_id' => $documentAnalysis->id,
            'total_batches' => $batches->count(),
            'reduce_level' => $this->currentReduceLevel
        ]);

        foreach ($batches as $batch) {
            $batchIndex++;

            // Monta o texto consolidado do batch
            $consolidatedText = $this->buildBatchText($batch);
            $parentIds = $batch->pluck('id')->toArray();

            // Cria registro para o resultado do reduce
            $reduceMicro = DocumentMicroAnalysis::create([
                'document_analysis_id' => $documentAnalysis->id,
                'document_index' => $batchIndex,
                'descricao' => "Consolidação nível {$this->currentReduceLevel} - Batch {$batchIndex}",
                'reduce_level' => $this->currentReduceLevel,
                'parent_ids' => $parentIds,
                'status' => 'processing',
            ]);

            try {
                // Aplica rate limiting
                RateLimiterService::apply($this->aiProvider);

                // Monta prompt de consolidação
                $prompt = $this->buildReducePrompt($batch->count());

                // Chama a IA para consolidar
                $result = $aiService->analyzeSingleDocument(
                    $prompt,
                    $consolidatedText,
                    $this->deepThinkingEnabled
                );

                $reduceMicro->markAsCompleted(
                    $result,
                    $this->estimateTokenCount($result)
                );

                Log::info('ReduceDocumentAnalysisJob: Batch consolidado', [
                    'analysis_id' => $documentAnalysis->id,
                    'batch' => $batchIndex,
                    'reduce_micro_id' => $reduceMicro->id
                ]);

            } catch (\Exception $e) {
                $reduceMicro->markAsFailed($e->getMessage());
                Log::error('ReduceDocumentAnalysisJob: Erro ao consolidar batch', [
                    'analysis_id' => $documentAnalysis->id,
                    'batch' => $batchIndex,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Verifica se deve continuar com mais um nível de reduce
        $completedReduces = $documentAnalysis->microAnalyses()
            ->reduceLevel($this->currentReduceLevel)
            ->completed()
            ->count();

        if ($completedReduces === 0) {
            $documentAnalysis->update([
                'status' => 'failed',
                'error_message' => "Falha na consolidação do nível {$this->currentReduceLevel}"
            ]);
            return;
        }

        if ($completedReduces > 1 && $this->currentReduceLevel < self::MAX_REDUCE_LEVELS) {
            // Precisa de mais um nível de reduce
            Log::info('ReduceDocumentAnalysisJob: Disparando próximo nível de reduce', [
                'analysis_id' => $documentAnalysis->id,
                'next_level' => $this->currentReduceLevel + 1,
                'micro_analyses_to_reduce' => $completedReduces
            ]);

            self::dispatch(
                $documentAnalysis->id,
                $this->aiProvider,
                $this->deepThinkingEnabled,
                $this->promptTemplate,
                $this->currentReduceLevel + 1
            )->onQueue('analysis');

        } else {
            // Pode gerar análise final
            $finalMicroAnalyses = $documentAnalysis->microAnalyses()
                ->reduceLevel($this->currentReduceLevel)
                ->completed()
                ->orderBy('document_index')
                ->get();

            $this->generateFinalAnalysis($documentAnalysis, $finalMicroAnalyses, microtime(true));
        }
    }

    /**
     * Gera a análise final consolidando todas as micro-análises restantes
     */
    private function generateFinalAnalysis(DocumentAnalysis $documentAnalysis, $microAnalyses, float $startTime): void
    {
        Log::info('ReduceDocumentAnalysisJob: Gerando análise final', [
            'analysis_id' => $documentAnalysis->id,
            'micro_analyses_count' => $microAnalyses->count()
        ]);

        $aiService = $this->getAIService($this->aiProvider);

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
            'ai_analysis' => $finalAnalysis,
            'processing_time_ms' => $totalProcessingTime,
            'is_resumable' => false,
            'last_processed_at' => now(),
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
     * Monta prompt para consolidação intermediária
     */
    private function buildReducePrompt(int $documentCount): string
    {
        return <<<PROMPT
# TAREFA DE CONSOLIDAÇÃO

Você recebeu as análises de {$documentCount} documentos de um processo judicial.

Sua tarefa é consolidar essas análises em um resumo estruturado que:

1. **Preserve a ordem cronológica** dos eventos do processo
2. **Identifique conexões** entre os documentos (causa e efeito)
3. **Destaque informações críticas** (pedidos, decisões, prazos)
4. **Mantenha referências** a documentos específicos quando relevante
5. **Seja conciso** mas não perca informações importantes

## FORMATO DE SAÍDA

Organize a consolidação nas seguintes seções:

### CRONOLOGIA DO PROCESSO
[Sequência temporal dos principais eventos]

### PARTES E REPRESENTANTES
[Quem são as partes e seus advogados/procuradores]

### PEDIDOS E PRETENSÕES
[O que cada parte está pedindo]

### DECISÕES E DESPACHOS
[O que já foi decidido até agora]

### FUNDAMENTOS JURÍDICOS
[Base legal utilizada pelas partes e pelo juízo]

### SITUAÇÃO ATUAL
[Estado atual do processo baseado nos documentos analisados]

---

Responda apenas com a consolidação, sem comentários adicionais.
PROMPT;
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
     * Estima contagem de tokens
     */
    private function estimateTokenCount(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
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
}
