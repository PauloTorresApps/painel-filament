<?php

namespace App\Jobs;

use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use App\Models\Setting;
use App\Services\AIServiceFactory;
use App\Traits\HandlesJsonOutput;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
    use Queueable, Batchable, HandlesJsonOutput;

    public int $timeout;
    public int $tries;
    public int $backoff;

    public function __construct(
        public int $microAnalysisId,
        public string $aiProvider,
        public bool $deepThinkingEnabled,
        public array $contextoDados,
        public ?string $aiModelId = null
    ) {
        $this->timeout = config('analysis.jobs.chunk_large_document.timeout', 3600);
        $this->tries = config('analysis.jobs.chunk_large_document.tries', 2);
        $this->backoff = config('analysis.jobs.chunk_large_document.backoff', 120);
    }

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

            // Verifica se já foi processada (evita duplicação em retry)
            if ($microAnalysis->isCompleted()) {
                Log::info('ChunkLargeDocumentJob: Já processada, pulando', [
                    'id' => $this->microAnalysisId
                ]);
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
            $aiService = AIServiceFactory::make($this->aiProvider);
            if ($this->aiModelId) {
                $aiService->setModel($this->aiModelId);
            }

            // Monta system prompt fixo para os chunks (cacheável entre chamadas)
            $chunkSystemPrompt = $this->buildChunkSystemPrompt($microAnalysis, $chunkCount);

            // Processa cada chunk e acumula os resumos
            $chunkSummaries = [];

            foreach ($chunks as $index => $chunk) {
                $chunkNum = $index + 1;

                // Verifica se o batch foi cancelado durante processamento
                if ($this->batch()?->cancelled()) {
                    Log::info('ChunkLargeDocumentJob: Batch cancelado durante processamento', [
                        'micro_id' => $this->microAnalysisId,
                        'chunk' => "{$chunkNum}/{$chunkCount}",
                    ]);
                    $microAnalysis->markAsFailed('Processamento cancelado pelo usuário');
                    return;
                }

                // Verifica se a análise pai foi cancelada
                $documentAnalysis->refresh();
                if ($documentAnalysis->status === 'cancelled') {
                    Log::info('ChunkLargeDocumentJob: Análise cancelada durante processamento', [
                        'micro_id' => $this->microAnalysisId,
                        'chunk' => "{$chunkNum}/{$chunkCount}",
                    ]);
                    $microAnalysis->markAsFailed('Análise cancelada pelo usuário');
                    return;
                }

                Log::info('ChunkLargeDocumentJob: Processando chunk', [
                    'micro_id' => $this->microAnalysisId,
                    'chunk' => "{$chunkNum}/{$chunkCount}",
                    'chunk_length' => mb_strlen($chunk),
                ]);

                // Monta prompt variável para este chunk específico (rate limiting aplicado pelo AI service)
                $prompt = $this->buildChunkPrompt($chunkNum, $chunkCount);

                // Analisa o chunk com system prompt cacheável
                $chunkResult = $aiService->analyzeSingleDocument(
                    $prompt,
                    $chunk,
                    false, // Não usa deep thinking para chunks individuais
                    $chunkSystemPrompt
                );

                $chunkSummaries[] = "### Parte {$chunkNum}/{$chunkCount}\n\n{$chunkResult}";

                // Atualiza progresso do documento grande
                $documentAnalysis->update([
                    'progress_message' => "Processando documento extenso: {$microAnalysis->descricao} ({$chunkNum}/{$chunkCount} partes)",
                    'last_processed_at' => now(),
                ]);
            }

            // Agora consolida todos os resumos dos chunks em um resumo final do documento
            Log::info('ChunkLargeDocumentJob: Consolidando chunks', [
                'micro_id' => $this->microAnalysisId,
                'total_chunks' => count($chunkSummaries),
            ]);

            $consolidatedText = implode("\n\n---\n\n", $chunkSummaries);
            $consolidationSystemPrompt = $this->buildConsolidationSystemPrompt($microAnalysis, $chunkCount);
            $consolidationPrompt = "Consolide as análises das {$chunkCount} partes do documento abaixo em uma análise única e coesa.";

            $finalResult = $aiService->analyzeSingleDocument(
                $consolidationPrompt,
                $consolidatedText,
                $this->deepThinkingEnabled,
                $consolidationSystemPrompt
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

            // Salva arquivo de debug com resultado da análise
            $this->saveAnalysisToFile($microAnalysis, $finalResult, $consolidationPrompt, $chunkSummaries, $textLength, $chunkCount);

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

        if ($textLength <= config('analysis.chunking.chunk_size_chars', 50000)) {
            return [$text];
        }

        // Divide por parágrafos para manter contexto
        $paragraphs = preg_split('/\n{2,}/', $text);
        $currentChunk = '';
        $currentLength = 0;

        foreach ($paragraphs as $paragraph) {
            $paragraphLength = mb_strlen($paragraph);

            // Se adicionar este parágrafo ultrapassar o limite
            if ($currentLength + $paragraphLength > config('analysis.chunking.chunk_size_chars', 50000) && $currentLength > config('analysis.chunking.min_chunk_size', 10000)) {
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
     * System prompt fixo para análise de chunks (cacheável entre chamadas).
     * Contém o contexto do documento e as instruções de extração (via config/prompts.php).
     */
    private function buildChunkSystemPrompt(DocumentMicroAnalysis $microAnalysis, int $totalChunks): string
    {
        $nomeClasse = $this->contextoDados['classeProcessualNome']
            ?? $this->contextoDados['classeProcessual']
            ?? 'Não informada';

        return str_replace(
            [':descricao', ':nomeClasse', ':totalChunks'],
            [$microAnalysis->descricao, $nomeClasse, (string) $totalChunks],
            config('prompts.chunk_analysis')
        );
    }

    /**
     * Prompt variável por chunk (apenas identifica qual parte está sendo analisada).
     */
    private function buildChunkPrompt(int $chunkNum, int $totalChunks): string
    {
        return "# PARTE {$chunkNum}/{$totalChunks}";
    }

    /**
     * System prompt para consolidação dos chunks (via config/prompts.php).
     */
    private function buildConsolidationSystemPrompt(DocumentMicroAnalysis $microAnalysis, int $chunkCount): string
    {
        $nomeClasse = $this->contextoDados['classeProcessualNome']
            ?? $this->contextoDados['classeProcessual']
            ?? 'Não informada';

        $consolidationPrompt = str_replace(
            [':descricao', ':nomeClasse', ':chunkCount'],
            [$microAnalysis->descricao, $nomeClasse, (string) $chunkCount],
            config('prompts.chunk_consolidation')
        );

        // Adiciona instruções de timeline
        $timelineInstructions = config('prompts.timeline_instructions');

        return $consolidationPrompt . "\n\n---\n\n" . $timelineInstructions;
    }


    /**
     * Verifica se um documento é considerado "grande"
     */
    public static function isLargeDocument(string $text): bool
    {
        return mb_strlen($text) > config('analysis.thresholds.large_document_chars', 100000);
    }

    /**
     * Salva o resultado da análise em arquivo para debug/inspeção
     */
    private function saveAnalysisToFile(
        DocumentMicroAnalysis $microAnalysis,
        string $result,
        string $consolidationPrompt,
        array $chunkSummaries,
        int $originalLength,
        int $chunkCount
    ): void {
        // Verifica se debug de arquivos está ativo
        if (!Setting::isDebugAnalysisFilesEnabled()) {
            return;
        }

        try {
            $documentAnalysis = $microAnalysis->documentAnalysis;
            $numeroProcesso = preg_replace('/[^0-9]/', '', $documentAnalysis->numero_processo ?? 'unknown');
            $analysisId = $documentAnalysis->id;
            $docIndex = str_pad($microAnalysis->document_index, 3, '0', STR_PAD_LEFT);
            $timestamp = now()->format('Y-m-d_H-i-s');

            // Cria diretório base para análises de debug
            $baseDir = "analises-debug/{$numeroProcesso}/analysis_{$analysisId}";

            // Arquivo com metadados + resultado completo
            $fileName = "{$docIndex}_{$timestamp}_" . \Illuminate\Support\Str::slug($microAnalysis->descricao, '_') . "_CHUNKED.md";

            // Formata os resumos dos chunks
            $chunkSummariesText = implode("\n\n---\n\n", $chunkSummaries);

            $content = <<<MD
# Análise do Documento GRANDE (Chunked): {$microAnalysis->descricao}

## Metadados

| Campo | Valor |
|-------|-------|
| **ID da Micro-Análise** | {$microAnalysis->id} |
| **ID da Análise Principal** | {$analysisId} |
| **Número do Processo** | {$documentAnalysis->numero_processo} |
| **Índice do Documento** | {$microAnalysis->document_index} |
| **Descrição** | {$microAnalysis->descricao} |
| **Mimetype** | {$microAnalysis->mimetype} |
| **Status** | {$microAnalysis->status} |
| **Token Count** | {$microAnalysis->token_count} |
| **Processing Time (ms)** | {$microAnalysis->processing_time_ms} |
| **Provider** | {$this->aiProvider} |
| **Model ID** | {$this->aiModelId} |
| **Deep Thinking** | {$this->deepThinkingEnabled} |
| **Data/Hora** | {$timestamp} |
| **DOCUMENTO GRANDE** | SIM |
| **Tamanho Original** | {$originalLength} caracteres |
| **Número de Chunks** | {$chunkCount} |

---

## Timeline Events (JSON extraído)

```json
{$this->formatJsonForDebug($microAnalysis->timeline_events)}
```

---

## Prompt de Consolidação Enviado à IA

```
{$consolidationPrompt}
```

---

## Resumos dos Chunks (entrada para consolidação)

{$chunkSummariesText}

---

## Resultado Final da Análise (micro_analysis)

{$result}

MD;

            Storage::disk('local')->put("{$baseDir}/{$fileName}", $content);

            Log::info('ChunkLargeDocumentJob: Arquivo de debug salvo', [
                'path' => "{$baseDir}/{$fileName}",
                'micro_id' => $microAnalysis->id
            ]);

        } catch (\Exception $e) {
            // Não falha a análise se não conseguir salvar o arquivo
            Log::warning('ChunkLargeDocumentJob: Falha ao salvar arquivo de debug', [
                'micro_id' => $microAnalysis->id,
                'error' => $e->getMessage()
            ]);
        }
    }

}
