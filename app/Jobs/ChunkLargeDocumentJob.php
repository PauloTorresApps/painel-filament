<?php

namespace App\Jobs;

use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use App\Models\Setting;
use App\Services\AIServiceFactory;
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
    use Queueable, Batchable;

    public int $timeout = 3600; // 1 hora - documentos muito grandes com muitos chunks
    public int $tries = 2;   // Reduzido para evitar duplicações
    public int $backoff = 120; // 2 minutos entre retries

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
    ) {
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
     * System prompt fixo para análise de chunks (cacheável entre chamadas).
     * Contém o contexto do documento e as instruções de extração.
     */
    private function buildChunkSystemPrompt(DocumentMicroAnalysis $microAnalysis, int $totalChunks): string
    {
        $nomeClasse = $this->contextoDados['classeProcessualNome']
            ?? $this->contextoDados['classeProcessual']
            ?? 'Não informada';

        return <<<PROMPT
Você é um assistente jurídico especializado em análise de documentos processuais extensos.

# CONTEXTO

**Documento:** {$microAnalysis->descricao}
**Classe Processual:** {$nomeClasse}
**Total de partes:** {$totalChunks}

Você está analisando partes individuais de um documento extenso dividido em {$totalChunks} partes.

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
     * Prompt variável por chunk (apenas identifica qual parte está sendo analisada).
     */
    private function buildChunkPrompt(int $chunkNum, int $totalChunks): string
    {
        return "# PARTE {$chunkNum}/{$totalChunks}";
    }

    /**
     * System prompt para consolidação dos chunks (contém todas as instruções fixas).
     */
    private function buildConsolidationSystemPrompt(DocumentMicroAnalysis $microAnalysis, int $chunkCount): string
    {
        $nomeClasse = $this->contextoDados['classeProcessualNome']
            ?? $this->contextoDados['classeProcessual']
            ?? 'Não informada';

        return <<<PROMPT
Você é um assistente jurídico especializado em análise de documentos processuais.

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

---

## LINHA DO TEMPO (JSON) - OBRIGATÓRIO

Ao final da análise, inclua um bloco JSON com todos os eventos e datas encontrados no documento.
O JSON deve estar entre as tags `<timeline_json>` e `</timeline_json>`.

Formato do JSON:
```json
{
  "eventos": [
    {
      "data": "YYYY-MM-DD",
      "data_original": "texto original da data no documento",
      "tipo": "tipo do evento (petição, decisão, prazo, fato, pagamento, etc.)",
      "descricao": "descrição curta do evento",
      "valores": ["R$ X.XXX,XX"],
      "relevancia": "alta|media|baixa"
    }
  ],
  "documento_data": "YYYY-MM-DD ou null",
  "documento_tipo": "tipo identificado do documento"
}
```

Regras para o JSON:
- Use formato ISO para datas (YYYY-MM-DD)
- Se a data tiver apenas mês/ano, use o dia 01 (ex: "2024-03-01")
- Se não conseguir determinar a data exata, use `null` no campo `data` mas mantenha `data_original`
- Liste TODOS os eventos com datas encontrados, mesmo os menos relevantes
- O campo `documento_data` é a data principal do documento (data de protocolo, assinatura, etc.)
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
     * Verifica se um documento é considerado "grande"
     */
    public static function isLargeDocument(string $text): bool
    {
        return mb_strlen($text) > self::LARGE_DOC_THRESHOLD;
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
{$this->formatJson($microAnalysis->timeline_events)}
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

    /**
     * Formata array/objeto para JSON legível
     */
    private function formatJson($data): string
    {
        if (empty($data)) {
            return 'null';
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: 'null';
    }
}
