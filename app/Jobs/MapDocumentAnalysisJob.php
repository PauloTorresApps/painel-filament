<?php

namespace App\Jobs;

use App\Models\AiPrompt;
use App\Models\DocumentMicroAnalysis;
use App\Models\Setting;
use App\Services\AIServiceFactory;
use App\Strategies\PdfNativeProcessingStrategy;
use App\Strategies\TextProcessingStrategy;
use App\Strategies\VisionProcessingStrategy;
use App\Traits\HandlesJsonOutput;
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
 * para aproveitar o prompt caching do OpenRouter (Anthropic, etc.),
 * reduzindo o custo de tokens repetidos entre documentos da mesma análise.
 */
class MapDocumentAnalysisJob implements ShouldQueue
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
        public ?string $aiModelId = null,
        public ?string $customAnalysisPrompt = null   // Prompt customizado para análise de documentos
    ) {
        $this->timeout = config('analysis.jobs.map_document.timeout', 300);
        $this->tries = config('analysis.jobs.map_document.tries', 3);
        $this->backoff = config('analysis.jobs.map_document.backoff', 30);
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
            $hasOriginalContent = $microAnalysis->hasOriginalContent();

            Log::info('MapDocumentAnalysisJob: Iniciando processamento', [
                'micro_id' => $this->microAnalysisId,
                'document_index' => $microAnalysis->document_index,
                'descricao' => $microAnalysis->descricao,
                'mimetype' => $microAnalysis->mimetype,
                'provider' => $this->aiProvider,
                'text_length' => $textLength,
                'processing_strategy' => $microAnalysis->processing_strategy,
                'has_original_content' => $hasOriginalContent,
                'is_scanned' => $microAnalysis->is_scanned,
            ]);

            // Valida se há conteúdo para analisar (texto OU arquivo original)
            if ($textLength === 0 && !$hasOriginalContent) {
                Log::warning('MapDocumentAnalysisJob: Documento sem texto extraído e sem conteúdo original', [
                    'micro_id' => $this->microAnalysisId,
                    'descricao' => $microAnalysis->descricao,
                ]);

                $microAnalysis->markAsFailed('Documento sem conteúdo para análise');
                return;
            }

            // Obtém o serviço de IA
            $aiService = AIServiceFactory::make($this->aiProvider);

            // Define o modelo: prioridade para roteamento por tipo de arquivo,
            // senão usa modelo configurado no prompt (aiModelId)
            if ($microAnalysis->processing_strategy) {
                $optimalModel = $aiService->getModelForStrategy($microAnalysis->processing_strategy);
                $aiService->setModel($optimalModel);
            } elseif ($this->aiModelId) {
                $aiService->setModel($this->aiModelId);
            }

            // Monta os prompts separados para prompt caching:
            // - System prompt: contexto fixo (idêntico para todos os docs da análise) → cacheado pelo provider
            // - Document prompt: conteúdo variável (específico por documento)
            $systemPrompt = $this->buildSystemPrompt();
            $documentPrompt = $this->buildDocumentPrompt($microAnalysis);

            // Roteia para a estratégia de análise mais adequada ao tipo de documento
            $result = $this->analyzeDocument($microAnalysis, $aiService, $systemPrompt, $documentPrompt);

            $processingTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            // Se o resultado é JSON estruturado, extrai análise e timeline separadamente
            $structuredData = $this->parseStructuredResult($result);

            if ($structuredData) {
                $analysisText = $structuredData['analise'];
                $microAnalysis->markAsCompleted(
                    $analysisText,
                    $this->estimateTokenCount($analysisText),
                    $processingTimeMs
                );

                // Sobrescreve timeline com dados estruturados (mais confiáveis que regex)
                $timelineData = $structuredData['timeline'] ?? null;
                if ($timelineData) {
                    $microAnalysis->update(['timeline_events' => $timelineData]);
                }

                Log::info('MapDocumentAnalysisJob: Resultado estruturado processado', [
                    'micro_id' => $this->microAnalysisId,
                    'classificacao' => $structuredData['classificacao'] ?? 'N/A',
                    'relevancia' => $structuredData['relevancia'] ?? 'N/A',
                    'tipo_documento' => $structuredData['tipo_documento'] ?? 'N/A',
                    'pontos_chave_count' => count($structuredData['pontos_chave'] ?? []),
                ]);
            } else {
                // Resultado em texto livre (fallback ou multimodal)
                $microAnalysis->markAsCompleted(
                    $result,
                    $this->estimateTokenCount($result),
                    $processingTimeMs
                );
            }

            // Captura annotation hash para cache de reanálise (P5)
            $metadata = $aiService->getLastAnalysisMetadata();
            if (!empty($metadata['file_annotations'])) {
                $hash = $metadata['file_annotations'][0]['hash'] ?? null;
                if ($hash) {
                    $microAnalysis->update(['file_annotation_hash' => $hash]);
                }
            }

            // Limpa arquivo original do disco para economizar espaço
            $microAnalysis->deleteOriginalContent();

            // Salva arquivo de debug com resultado da análise
            $this->saveAnalysisToFile($microAnalysis, $result, $systemPrompt, $documentPrompt, $metadata);

            Log::info('MapDocumentAnalysisJob: Concluído com sucesso', [
                'micro_id' => $this->microAnalysisId,
                'processing_time_ms' => $processingTimeMs,
                'strategy_used' => $microAnalysis->processing_strategy,
            ]);

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
     * permitindo que o OpenRouter (Anthropic, etc.) o cacheem
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

        $systemRole = config('prompts.system_role', 'Você é um assistente jurídico especializado em análise de documentos processuais. Forneça análises objetivas, estruturadas e fundamentadas.');

        $basePrompt = <<<PROMPT
{$systemRole}

# CONTEXTO DO PROCESSO

**Classe Processual:** {$nomeClasse}
**Assuntos:** {$assuntos}
**Número do Processo:** {$numeroProcesso}

---

{$tarefaPrompt}
PROMPT;

        // Se structured outputs está habilitado, o schema JSON já define a estrutura da resposta
        // Não precisa instruir o modelo a incluir timeline JSON no texto
        if (config('services.openrouter.structured_map_enabled', false)) {
            $formatInstructions = config('prompts.map_structured_format', '**FORMATO:** Preencha todos os campos do JSON schema solicitado. O campo `analise` deve conter a análise completa em markdown. Seja conciso mas completo.');
            return $basePrompt . "\n\n---\n\n" . $formatInstructions;
        }

        // Modo texto livre: instrui o modelo a incluir timeline JSON entre tags
        $timelineInstructions = config('prompts.timeline_instructions');
        $formatInstructions = config('prompts.map_freetext_format', '**FORMATO:** Responda de forma estruturada usando markdown. Seja conciso mas completo. Não esqueça do bloco JSON ao final.');

        return $basePrompt . "\n\n---\n\n" . $timelineInstructions . "\n\n---\n\n" . $formatInstructions;
    }

    /**
     * Roteia o documento para a estratégia de análise mais adequada.
     *
     * Prioridade (Strategy Pattern):
     * 1. VisionProcessingStrategy → imagem via visão direta
     * 2. PdfNativeProcessingStrategy → PDF nativo com plugin (pdf-text ou mistral-ocr)
     * 3. TextProcessingStrategy → texto extraído (fallback)
     *
     * Se uma estratégia falhar, tenta a próxima na cadeia.
     */
    private function analyzeDocument(
        DocumentMicroAnalysis $microAnalysis,
        \App\Contracts\AIProviderInterface $aiService,
        string $systemPrompt,
        string $documentPrompt
    ): string {
        $strategies = [
            new VisionProcessingStrategy(),
            new PdfNativeProcessingStrategy(),
            new TextProcessingStrategy($this->getMapAnalysisSchema()),
        ];

        Log::info('MapDocumentAnalysisJob: Estratégia de processamento', [
            'micro_id' => $microAnalysis->id,
            'strategy' => $microAnalysis->processing_strategy ?? 'text',
            'is_scanned' => $microAnalysis->is_scanned,
            'has_original' => $microAnalysis->hasOriginalContent(),
            'has_text' => mb_strlen($microAnalysis->extracted_text ?? '') > 0,
        ]);

        foreach ($strategies as $strategy) {
            if ($strategy->canHandle($microAnalysis)) {
                try {
                    return $strategy->process(
                        $microAnalysis,
                        $aiService,
                        $systemPrompt,
                        $documentPrompt,
                        $this->deepThinkingEnabled
                    );
                } catch (\Exception $e) {
                    Log::warning('MapDocumentAnalysisJob: Strategy falhou, tentando próxima', [
                        'micro_id' => $microAnalysis->id,
                        'strategy' => get_class($strategy),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        throw new \RuntimeException("Nenhuma estratégia de processamento conseguiu processar o documento {$microAnalysis->id}");
    }

    /**
     * Monta o prompt variável específico para cada documento.
     *
     * Contém apenas o descriptor do documento (nome, índice, tipo).
     * O texto do documento é passado separadamente via analyzeSingleDocument().
     */
    private function buildDocumentPrompt(DocumentMicroAnalysis $microAnalysis): string
    {
        $strategy = $microAnalysis->processing_strategy ?? 'text';

        $documentContext = "**Documento:** {$microAnalysis->descricao}\n**Índice:** {$microAnalysis->document_index}";

        // Adiciona contexto sobre o tipo de processamento
        if ($strategy === 'vision') {
            $documentContext .= "\n**Tipo original:** {$microAnalysis->mimetype} (imagem do documento)";
        } elseif ($strategy === 'pdf_ocr') {
            $documentContext .= "\n**Tipo original:** PDF escaneado";
        } elseif ($strategy === 'pdf_text') {
            $documentContext .= "\n**Tipo original:** PDF com texto";
        }

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
     * Constrói o prompt de tarefa padrão do sistema (via config/prompts.php)
     */
    private function buildDefaultTaskPrompt(): string
    {
        return config('prompts.map_default_task');
    }

    /**
     * Retorna o JSON Schema para a resposta estruturada da fase MAP.
     * Usado com o recurso de structured outputs da OpenRouter.
     */
    private function getMapAnalysisSchema(): array
    {
        return [
            'name' => 'document_analysis',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'analise' => [
                        'type' => 'string',
                        'description' => 'Análise completa do documento em formato markdown, incluindo tipo de manifestação, partes, pedidos/decisões, fundamentos e fatos relevantes',
                    ],
                    'tipo_documento' => [
                        'type' => 'string',
                        'description' => 'Tipo do documento identificado (petição inicial, contestação, decisão, despacho, sentença, recurso, parecer, comprovante, etc.)',
                    ],
                    'classificacao' => [
                        'type' => 'string',
                        'enum' => ['favoravel', 'desfavoravel', 'neutro', 'informativo'],
                        'description' => 'Classificação do documento quanto à posição processual',
                    ],
                    'relevancia' => [
                        'type' => 'string',
                        'enum' => ['alta', 'media', 'baixa'],
                        'description' => 'Nível de relevância do documento para o desfecho do processo',
                    ],
                    'resumo' => [
                        'type' => 'string',
                        'description' => 'Resumo do documento em uma frase curta (máximo 200 caracteres)',
                    ],
                    'pontos_chave' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Lista dos pontos mais importantes encontrados no documento',
                    ],
                    'partes_mencionadas' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Nomes das partes mencionadas no documento',
                    ],
                    'valores_monetarios' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Valores monetários mencionados (formato: R$ X.XXX,XX)',
                    ],
                    'timeline' => [
                        'type' => 'object',
                        'properties' => [
                            'eventos' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => ['type' => ['string', 'null'], 'description' => 'Data no formato YYYY-MM-DD ou null'],
                                        'data_original' => ['type' => 'string', 'description' => 'Texto original da data como aparece no documento'],
                                        'tipo' => ['type' => 'string', 'description' => 'Tipo do evento (petição, decisão, prazo, fato, pagamento, etc.)'],
                                        'descricao' => ['type' => 'string', 'description' => 'Descrição curta do evento'],
                                        'valores' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Valores monetários associados'],
                                        'relevancia' => ['type' => 'string', 'enum' => ['alta', 'media', 'baixa']],
                                    ],
                                    'required' => ['data', 'tipo', 'descricao', 'relevancia'],
                                    'additionalProperties' => false,
                                ],
                            ],
                            'documento_data' => ['type' => ['string', 'null'], 'description' => 'Data principal do documento (YYYY-MM-DD ou null)'],
                            'documento_tipo' => ['type' => 'string', 'description' => 'Tipo identificado do documento'],
                        ],
                        'required' => ['eventos', 'documento_data', 'documento_tipo'],
                        'additionalProperties' => false,
                    ],
                ],
                'required' => ['analise', 'tipo_documento', 'classificacao', 'relevancia', 'resumo', 'pontos_chave', 'partes_mencionadas', 'valores_monetarios', 'timeline'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * Tenta parsear o resultado como JSON estruturado.
     * Retorna o array parseado ou null se não for JSON válido.
     */
    private function parseStructuredResult(string $result): ?array
    {
        // Verifica se parece JSON (começa com { após whitespace)
        $trimmed = ltrim($result);
        if (!str_starts_with($trimmed, '{')) {
            return null;
        }

        try {
            $data = $this->jsonDecode($result);

            // Valida que tem pelo menos o campo 'analise'
            if (!isset($data['analise']) || empty($data['analise'])) {
                return null;
            }

            return $data;
        } catch (\JsonException $e) {
            Log::warning('MapDocumentAnalysisJob: Resultado parece JSON mas falhou ao parsear', [
                'micro_id' => $this->microAnalysisId,
                'error' => $e->getMessage(),
                'preview' => mb_substr($result, 0, 200),
            ]);
            return null;
        }
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
    private function saveAnalysisToFile(DocumentMicroAnalysis $microAnalysis, string $result, string $systemPrompt, string $documentPrompt, array $apiMetadata = []): void
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

            // Extrai metadados da API (null coalescing não funciona em heredoc)
            $metaModeloApi = $apiMetadata['model'] ?? 'N/A';
            $metaTokensPrompt = $apiMetadata['total_prompt_tokens'] ?? 'N/A';
            $metaTokensCompletion = $apiMetadata['total_completion_tokens'] ?? 'N/A';
            $metaTokensReasoning = $apiMetadata['total_reasoning_tokens'] ?? 0;
            $metaTokensTotal = $apiMetadata['total_tokens'] ?? 'N/A';
            $metaApiCalls = $apiMetadata['api_calls_count'] ?? 1;

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
| **Modelo Resposta API** | {$metaModeloApi} |
| **Tokens Enviados (Prompt)** | {$metaTokensPrompt} |
| **Tokens Recebidos (Completion)** | {$metaTokensCompletion} |
| **Tokens de Raciocínio** | {$metaTokensReasoning} |
| **Total de Tokens** | {$metaTokensTotal} |
| **Chamadas à API** | {$metaApiCalls} |

---

## Timeline Events (JSON extraído)

```json
{$this->formatJsonForDebug($microAnalysis->timeline_events)}
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

}
