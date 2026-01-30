<?php

namespace App\Jobs;

use App\Models\DocumentMicroAnalysis;
use App\Models\DocumentAnalysis;
use App\Contracts\AIProviderInterface;
use App\Services\GeminiService;
use App\Services\DeepSeekService;
use App\Services\OpenAIService;
use App\Services\RateLimiterService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job para processar um batch individual da fase REDUCE.
 * Utiliza o trait Batchable para processamento paralelo via Bus::batch().
 */
class ReduceBatchJob implements ShouldQueue
{
    use Queueable, Batchable;

    public int $timeout = 600; // 10 minutos por batch
    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public int $documentAnalysisId,
        public array $microAnalysisIds, // IDs das micro-análises a consolidar
        public int $batchIndex,
        public int $reduceLevel,
        public string $aiProvider,
        public bool $deepThinkingEnabled,
        public ?string $aiModelId = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Verifica se o batch foi cancelado
        if ($this->batch()?->cancelled()) {
            Log::info('ReduceBatchJob: Batch cancelado', [
                'analysis_id' => $this->documentAnalysisId,
                'batch_index' => $this->batchIndex,
            ]);
            return;
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

            // Cria registro para o resultado do reduce
            $reduceMicro = DocumentMicroAnalysis::create([
                'document_analysis_id' => $this->documentAnalysisId,
                'document_index' => $this->batchIndex,
                'descricao' => "Consolidação nível {$this->reduceLevel} - Batch {$this->batchIndex}",
                'reduce_level' => $this->reduceLevel,
                'parent_ids' => $this->microAnalysisIds,
                'status' => 'processing',
            ]);

            // Obtém o serviço de IA
            $aiService = $this->getAIService($this->aiProvider);

            // Define o modelo específico se configurado
            if ($this->aiModelId) {
                $aiService->setModel($this->aiModelId);
            }

            // Monta o texto consolidado do batch
            $consolidatedText = $this->buildBatchText($microAnalyses);

            // Aplica rate limiting
            RateLimiterService::apply($this->aiProvider);

            // Monta prompt de consolidação
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
     * Estima contagem de tokens
     */
    private function estimateTokenCount(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
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
