<?php

namespace App\Jobs;

use App\Models\AiPrompt;
use App\Models\DocumentMicroAnalysis;
use App\Models\Setting;
use App\Services\AIServiceFactory;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job responsável pela fase MAP do map-reduce.
 * Processa um único documento e gera uma micro-análise.
 *
 * Utiliza o trait Batchable para processamento paralelo via Bus::batch().
 * A coordenação do REDUCE é feita pelo DispatchMapPhaseJob através de callbacks.
 *
 * O prompt é separado em system prompt (fixo, cacheável) e user prompt (variável por documento)
 * para aproveitar o prompt caching dos provedores de IA (Anthropic, OpenAI, etc.),
 * reduzindo o custo de tokens repetidos entre documentos da mesma análise.
 */
class MapDocumentAnalysisJob implements ShouldQueue
{
    use Queueable, Batchable;

    public int $timeout = 300; // 5 minutos por documento
    public int $tries = 3;
    public int $backoff = 30; // 30 segundos entre tentativas

    public function __construct(
        public int $microAnalysisId,
        public string $aiProvider,
        public bool $deepThinkingEnabled,
        public array $contextoDados,
        public ?string $aiModelId = null,             // ID do modelo específico (ex: gemini-2.5-flash)
        public ?string $customAnalysisPrompt = null   // Prompt customizado para análise de documentos
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Verifica se o batch foi cancelado
        if ($this->batch()?->cancelled()) {
            Log::info('MapDocumentAnalysisJob: Batch cancelado, pulando', [
                'micro_id' => $this->microAnalysisId
            ]);
            return;
        }

        $startTime = microtime(true);

        try {
            $microAnalysis = DocumentMicroAnalysis::find($this->microAnalysisId);

            if (!$microAnalysis) {
                Log::error('MapDocumentAnalysisJob: MicroAnalysis não encontrada', [
                    'id' => $this->microAnalysisId
                ]);
                return;
            }

            // Verifica se já foi processada
            if ($microAnalysis->isCompleted()) {
                Log::info('MapDocumentAnalysisJob: Já processada, pulando', [
                    'id' => $this->microAnalysisId
                ]);
                return;
            }

            // Verifica se a análise pai foi cancelada
            $documentAnalysis = $microAnalysis->documentAnalysis;
            if (!$documentAnalysis || $documentAnalysis->status === 'cancelled') {
                Log::info('MapDocumentAnalysisJob: Análise pai cancelada', [
                    'micro_id' => $this->microAnalysisId
                ]);
                return;
            }

            $microAnalysis->markAsProcessing();

            $textLength = mb_strlen($microAnalysis->extracted_text ?? '');

            Log::info('MapDocumentAnalysisJob: Iniciando processamento', [
                'micro_id' => $this->microAnalysisId,
                'document_index' => $microAnalysis->document_index,
                'descricao' => $microAnalysis->descricao,
                'mimetype' => $microAnalysis->mimetype,
                'provider' => $this->aiProvider,
                'text_length' => $textLength,
            ]);

            // Valida se há texto extraído para analisar
            if ($textLength === 0) {
                Log::warning('MapDocumentAnalysisJob: Documento sem texto extraído', [
                    'micro_id' => $this->microAnalysisId,
                    'descricao' => $microAnalysis->descricao,
                ]);

                $microAnalysis->markAsFailed('Documento sem texto extraído para análise');
                return;
            }

            // Obtém o serviço de IA
            $aiService = AIServiceFactory::make($this->aiProvider);

            // Define o modelo específico se configurado
            if ($this->aiModelId) {
                $aiService->setModel($this->aiModelId);
            }

            // Monta os prompts separados para prompt caching:
            // - System prompt: contexto fixo (idêntico para todos os docs da análise) → cacheado pelo provider
            // - Document prompt: conteúdo variável (específico por documento)
            $systemPrompt = $this->buildSystemPrompt();
            $documentPrompt = $this->buildDocumentPrompt($microAnalysis);

            // Chama a IA para análise de texto (rate limiting é aplicado internamente pelo AI service) (imagens já tiveram texto extraído via OCR)
            $result = $aiService->analyzeSingleDocument(
                $documentPrompt,
                $microAnalysis->extracted_text,
                $this->deepThinkingEnabled,
                $systemPrompt
            );

            $processingTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            // Marca como completo
            $microAnalysis->markAsCompleted(
                $result,
                $this->estimateTokenCount($result),
                $processingTimeMs
            );

            // Salva arquivo de debug com resultado da análise
            $this->saveAnalysisToFile($microAnalysis, $result, $systemPrompt, $documentPrompt);

            Log::info('MapDocumentAnalysisJob: Concluído com sucesso', [
                'micro_id' => $this->microAnalysisId,
                'processing_time_ms' => $processingTimeMs
            ]);

            // Nota: A coordenação do REDUCE é feita pelo Bus::batch() callback
            // no DispatchMapPhaseJob, não mais aqui

        } catch (\Exception $e) {
            Log::error('MapDocumentAnalysisJob: Erro no processamento', [
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
     * Monta o system prompt com todo o contexto fixo da análise.
     *
     * Este conteúdo é IDÊNTICO para todos os documentos de uma mesma análise,
     * permitindo que os provedores de IA (Anthropic, OpenAI, etc.) o cacheem
     * e cobrem apenas uma fração do custo nos documentos subsequentes.
     *
     * Inclui: papel do assistente, contexto do processo, tarefa de análise,
     * schema JSON da timeline e instruções de formato.
     */
    private function buildSystemPrompt(): string
    {
        $nomeClasse = $this->contextoDados['classeProcessualNome']
            ?? $this->contextoDados['classeProcessual']
            ?? 'Não informada';

        $assuntos = $this->formatAssuntos($this->contextoDados['assunto'] ?? []);
        $numeroProcesso = $this->contextoDados['numeroProcesso'] ?? 'Não informado';

        // Busca o prompt padrão ativo de "Análise de Documentos" do banco
        // Isso garante que sempre use o prompt mais atual configurado
        $promptFromDb = AiPrompt::getDefaultForSystemAndType(1, AiPrompt::TYPE_DOCUMENT_ANALYSIS);

        // Prioridade: 1º prompt do banco, 2º prompt passado como parâmetro, 3º prompt padrão hardcoded
        if ($promptFromDb) {
            $tarefaPrompt = $this->buildCustomTaskPrompt($promptFromDb->content);
        } elseif ($this->customAnalysisPrompt) {
            $tarefaPrompt = $this->buildCustomTaskPrompt($this->customAnalysisPrompt);
        } else {
            $tarefaPrompt = $this->buildDefaultTaskPrompt();
        }

        return <<<PROMPT
Você é um assistente jurídico especializado em análise de documentos processuais. Forneça análises objetivas, estruturadas e fundamentadas.

# CONTEXTO DO PROCESSO

**Classe Processual:** {$nomeClasse}
**Assuntos:** {$assuntos}
**Número do Processo:** {$numeroProcesso}

---

{$tarefaPrompt}

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

---

**FORMATO:** Responda de forma estruturada usando markdown. Seja conciso mas completo. Não esqueça do bloco JSON ao final.
PROMPT;
    }

    /**
     * Monta o prompt variável específico para cada documento.
     *
     * Contém apenas o descriptor do documento (nome, índice, tipo).
     * O texto do documento é passado separadamente via analyzeSingleDocument().
     */
    private function buildDocumentPrompt(DocumentMicroAnalysis $microAnalysis): string
    {
        // Adiciona contexto se o documento original era uma imagem (texto extraído via OCR)
        $documentContext = $microAnalysis->isImage()
            ? "**Documento:** {$microAnalysis->descricao}\n**Índice:** {$microAnalysis->document_index}\n**Tipo original:** {$microAnalysis->mimetype} (texto extraído via OCR)"
            : "**Documento:** {$microAnalysis->descricao}\n**Índice:** {$microAnalysis->document_index}";

        return <<<PROMPT
# DOCUMENTO A ANALISAR

{$documentContext}
PROMPT;
    }

    /**
     * Constrói o prompt de tarefa customizado (definido pelo usuário ou do banco)
     */
    private function buildCustomTaskPrompt(string $promptContent): string
    {
        return <<<PROMPT
# TAREFA DE ANÁLISE

{$promptContent}
PROMPT;
    }

    /**
     * Constrói o prompt de tarefa padrão do sistema
     */
    private function buildDefaultTaskPrompt(): string
    {
        return <<<PROMPT
# TAREFA

Analise o documento acima e extraia as seguintes informações de forma estruturada:

## 1. TIPO DE MANIFESTAÇÃO
Identifique o tipo (petição inicial, contestação, decisão, despacho, sentença, recurso, parecer, documento pessoal, comprovante, etc.)

## 2. PARTES ENVOLVIDAS
Liste as partes mencionadas e seus papéis (autor, réu, terceiros, advogados, etc.)

## 3. PEDIDOS OU DECISÕES
- Se for petição/recurso: liste os pedidos formulados
- Se for decisão/sentença: liste o dispositivo (o que foi decidido)
- Se for documento/comprovante: descreva o conteúdo principal

## 4. FUNDAMENTOS
- Fundamentos legais citados (artigos de lei, jurisprudência)
- Argumentos principais utilizados

## 5. FATOS RELEVANTES
Fatos narrados que são importantes para entender a narrativa processual

## 6. CONEXÕES
Referências a outros documentos ou eventos do processo
PROMPT;
    }

    /**
     * Estima contagem de tokens baseado no tamanho do texto
     */
    private function estimateTokenCount(string $text): int
    {
        // Aproximação: ~4 caracteres por token
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * Formata array de assuntos para string legível
     */
    private function formatAssuntos(array $assuntos): string
    {
        if (empty($assuntos)) {
            return 'Não informados';
        }

        $nomes = array_map(function ($assunto) {
            return $assunto['nomeAssunto']
                ?? $assunto['descricao']
                ?? $assunto['codigoAssunto']
                ?? 'Assunto';
        }, $assuntos);

        return implode(', ', $nomes);
    }

    /**
     * Salva o resultado da análise em arquivo para debug/inspeção
     */
    private function saveAnalysisToFile(DocumentMicroAnalysis $microAnalysis, string $result, string $systemPrompt, string $documentPrompt): void
    {
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
            $fileName = "{$docIndex}_{$timestamp}_" . \Illuminate\Support\Str::slug($microAnalysis->descricao, '_') . ".md";

            $content = <<<MD
# Análise do Documento: {$microAnalysis->descricao}

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

---

## Timeline Events (JSON extraído)

```json
{$this->formatJson($microAnalysis->timeline_events)}
```

---

## System Prompt (contexto fixo - cacheável entre documentos)

```
{$systemPrompt}
```

---

## Document Prompt (variável por documento)

```
{$documentPrompt}
```

---

## Texto Original do Documento (primeiros 2000 caracteres)

```
{$this->truncateText($microAnalysis->extracted_text, 2000)}
```

---

## Resultado da Análise (micro_analysis)

{$result}

MD;

            Storage::disk('local')->put("{$baseDir}/{$fileName}", $content);

            Log::info('MapDocumentAnalysisJob: Arquivo de debug salvo', [
                'path' => "{$baseDir}/{$fileName}",
                'micro_id' => $microAnalysis->id
            ]);

        } catch (\Exception $e) {
            // Não falha a análise se não conseguir salvar o arquivo
            Log::warning('MapDocumentAnalysisJob: Falha ao salvar arquivo de debug', [
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

    /**
     * Trunca texto para exibição
     */
    private function truncateText(?string $text, int $maxLength): string
    {
        if (empty($text)) {
            return '(vazio)';
        }

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength) . "\n\n... [TRUNCADO - Total: " . mb_strlen($text) . " caracteres]";
    }

}
