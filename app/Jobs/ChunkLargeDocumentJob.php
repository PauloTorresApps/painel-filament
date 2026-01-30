<?php

namespace App\Jobs;

use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
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
 * Job para processar documentos grandes (>50 páginas ou >100k caracteres).
 *
 * Estratégia: Divide o documento em chunks menores, analisa cada chunk
 * e gera um "Resumo do Documento" antes de enviá-lo para a análise principal.
 *
 * Isso evita perda de informação em documentos muito extensos.
 */
class ChunkLargeDocumentJob implements ShouldQueue
{
    use Queueable, Batchable;

    public int $timeout = 600; // 10 minutos
    public int $tries = 3;
    public int $backoff = 60;

    // Configuração de chunking
    public const CHUNK_SIZE_CHARS = 50000; // ~50 páginas (1000 chars/página)
    public const MIN_CHUNK_SIZE = 10000;   // Mínimo para evitar chunks muito pequenos
    public const LARGE_DOC_THRESHOLD = 100000; // 100k chars = documento grande

    public function __construct(
        public int $microAnalysisId,
        public string $aiProvider,
        public bool $deepThinkingEnabled,
        public array $contextoDados,
        public ?string $aiModelId = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Verifica se o batch foi cancelado
        if ($this->batch()?->cancelled()) {
            Log::info('ChunkLargeDocumentJob: Batch cancelado', [
                'micro_id' => $this->microAnalysisId
            ]);
            return;
        }

        $startTime = microtime(true);

        try {
            $microAnalysis = DocumentMicroAnalysis::find($this->microAnalysisId);

            if (!$microAnalysis) {
                Log::error('ChunkLargeDocumentJob: MicroAnalysis não encontrada', [
                    'id' => $this->microAnalysisId
                ]);
                return;
            }

            $documentAnalysis = $microAnalysis->documentAnalysis;
            if (!$documentAnalysis || $documentAnalysis->status === 'cancelled') {
                return;
            }

            $text = $microAnalysis->extracted_text ?? '';
            $textLength = mb_strlen($text);

            Log::info('ChunkLargeDocumentJob: Processando documento grande', [
                'micro_id' => $this->microAnalysisId,
                'text_length' => $textLength,
                'descricao' => $microAnalysis->descricao,
            ]);

            $microAnalysis->markAsProcessing();

            // Divide o texto em chunks
            $chunks = $this->splitTextIntoChunks($text);
            $chunkCount = count($chunks);

            Log::info('ChunkLargeDocumentJob: Documento dividido em chunks', [
                'micro_id' => $this->microAnalysisId,
                'chunk_count' => $chunkCount,
            ]);

            // Obtém o serviço de IA
            $aiService = $this->getAIService($this->aiProvider);
            if ($this->aiModelId) {
                $aiService->setModel($this->aiModelId);
            }

            // Processa cada chunk e acumula os resumos
            $chunkSummaries = [];

            foreach ($chunks as $index => $chunk) {
                $chunkNum = $index + 1;

                Log::info('ChunkLargeDocumentJob: Processando chunk', [
                    'micro_id' => $this->microAnalysisId,
                    'chunk' => "{$chunkNum}/{$chunkCount}",
                    'chunk_length' => mb_strlen($chunk),
                ]);

                // Aplica rate limiting
                RateLimiterService::apply($this->aiProvider);

                // Monta prompt para análise do chunk
                $prompt = $this->buildChunkPrompt($microAnalysis, $chunkNum, $chunkCount);

                // Analisa o chunk
                $chunkResult = $aiService->analyzeSingleDocument(
                    $prompt,
                    $chunk,
                    false // Não usa deep thinking para chunks individuais
                );

                $chunkSummaries[] = "### Parte {$chunkNum}/{$chunkCount}\n\n{$chunkResult}";
            }

            // Agora consolida todos os resumos dos chunks em um resumo final do documento
            Log::info('ChunkLargeDocumentJob: Consolidando chunks', [
                'micro_id' => $this->microAnalysisId,
                'total_chunks' => count($chunkSummaries),
            ]);

            RateLimiterService::apply($this->aiProvider);

            $consolidatedText = implode("\n\n---\n\n", $chunkSummaries);
            $consolidationPrompt = $this->buildConsolidationPrompt($microAnalysis, $chunkCount);

            $finalResult = $aiService->analyzeSingleDocument(
                $consolidationPrompt,
                $consolidatedText,
                $this->deepThinkingEnabled
            );

            $processingTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            // Marca como completo com o resumo consolidado
            $microAnalysis->markAsCompleted(
                $finalResult,
                $this->estimateTokenCount($finalResult),
                $processingTimeMs
            );

            // Salva metadados sobre o chunking
            $microAnalysis->update([
                'parent_ids' => [
                    'chunked' => true,
                    'chunk_count' => $chunkCount,
                    'original_length' => $textLength,
                ]
            ]);

            Log::info('ChunkLargeDocumentJob: Documento grande processado com sucesso', [
                'micro_id' => $this->microAnalysisId,
                'chunks_processed' => $chunkCount,
                'processing_time_ms' => $processingTimeMs,
            ]);

        } catch (\Exception $e) {
            Log::error('ChunkLargeDocumentJob: Erro no processamento', [
                'micro_id' => $this->microAnalysisId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if (isset($microAnalysis)) {
                $microAnalysis->markAsFailed($e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Divide o texto em chunks respeitando limites de parágrafo
     */
    private function splitTextIntoChunks(string $text): array
    {
        $chunks = [];
        $textLength = mb_strlen($text);

        if ($textLength <= self::CHUNK_SIZE_CHARS) {
            return [$text];
        }

        // Divide por parágrafos para manter contexto
        $paragraphs = preg_split('/\n{2,}/', $text);
        $currentChunk = '';
        $currentLength = 0;

        foreach ($paragraphs as $paragraph) {
            $paragraphLength = mb_strlen($paragraph);

            // Se adicionar este parágrafo ultrapassar o limite
            if ($currentLength + $paragraphLength > self::CHUNK_SIZE_CHARS && $currentLength > self::MIN_CHUNK_SIZE) {
                $chunks[] = trim($currentChunk);
                $currentChunk = $paragraph;
                $currentLength = $paragraphLength;
            } else {
                $currentChunk .= ($currentChunk ? "\n\n" : '') . $paragraph;
                $currentLength += $paragraphLength;
            }
        }

        // Adiciona o último chunk
        if (trim($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * Prompt para análise de um chunk individual
     */
    private function buildChunkPrompt(DocumentMicroAnalysis $microAnalysis, int $chunkNum, int $totalChunks): string
    {
        $nomeClasse = $this->contextoDados['classeProcessualNome']
            ?? $this->contextoDados['classeProcessual']
            ?? 'Não informada';

        return <<<PROMPT
# ANÁLISE DE DOCUMENTO EXTENSO - PARTE {$chunkNum}/{$totalChunks}

**Documento:** {$microAnalysis->descricao}
**Classe Processual:** {$nomeClasse}

Você está analisando a PARTE {$chunkNum} de {$totalChunks} de um documento extenso.

## TAREFA

Extraia as informações relevantes DESTA PARTE do documento:

1. **Fatos narrados** nesta seção
2. **Datas importantes** mencionadas
3. **Valores monetários** se houver
4. **Partes/pessoas** citadas
5. **Decisões ou pedidos** formulados nesta parte
6. **Referências legais** (artigos, leis, jurisprudência)

## IMPORTANTE
- Seja objetivo e extraia apenas o que está NESTA PARTE
- Não tente concluir a análise - outras partes serão analisadas separadamente
- Mantenha referências a "páginas" ou "seções" se mencionadas

Responda em markdown estruturado.
PROMPT;
    }

    /**
     * Prompt para consolidar os chunks em um resumo único
     */
    private function buildConsolidationPrompt(DocumentMicroAnalysis $microAnalysis, int $chunkCount): string
    {
        $nomeClasse = $this->contextoDados['classeProcessualNome']
            ?? $this->contextoDados['classeProcessual']
            ?? 'Não informada';

        return <<<PROMPT
# CONSOLIDAÇÃO DE DOCUMENTO EXTENSO

**Documento:** {$microAnalysis->descricao}
**Classe Processual:** {$nomeClasse}
**Total de partes analisadas:** {$chunkCount}

Você recebeu a análise de {$chunkCount} partes de um documento extenso.

## TAREFA

Consolide todas as informações em uma ANÁLISE ÚNICA E COESA que:

1. **PRESERVE a ordem cronológica** dos eventos narrados
2. **UNIFIQUE informações** que aparecem em múltiplas partes
3. **REMOVA redundâncias** mantendo a completude
4. **IDENTIFIQUE o tipo** do documento (petição, decisão, laudo, etc.)
5. **DESTAQUE os pontos principais**:
   - Pedidos/decisões centrais
   - Fatos mais relevantes
   - Valores e datas importantes
   - Fundamentos legais

## FORMATO

Responda com uma análise estruturada em markdown, como se fosse a análise de um único documento.

NÃO mencione que o documento foi dividido em partes - o resultado deve parecer uma análise contínua.
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

    /**
     * Verifica se um documento é considerado "grande"
     */
    public static function isLargeDocument(string $text): bool
    {
        return mb_strlen($text) > self::LARGE_DOC_THRESHOLD;
    }
}
