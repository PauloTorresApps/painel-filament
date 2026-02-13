<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterService extends AbstractAIService
{
    protected int $timeout;

    public function __construct()
    {
        $this->apiKey = config('services.openrouter.api_key') ?? config('laravel-openrouter.api_key');
        $this->apiUrl = config('services.openrouter.api_url') ?? config('laravel-openrouter.api_endpoint');
        $this->model = config('services.openrouter.model', 'anthropic/claude-sonnet-4');
        $this->timeout = (int) config('services.openrouter.timeout', 300);

        if (empty($this->apiKey)) {
            throw new \Exception('OPENROUTER_API_KEY não configurado no .env');
        }
    }

    /**
     * Retorna o nome do rate limiter para este provider
     */
    protected function getRateLimiterKey(): string
    {
        return 'openrouter';
    }

    /**
     * Retorna o nome do provider
     */
    public function getName(): string
    {
        return 'OpenRouter';
    }

    /**
     * Verifica se o modelo suporta reasoning (pensamento profundo)
     */
    protected function supportsReasoning(): bool
    {
        $reasoningModels = [
            // DeepSeek
            'deepseek/deepseek-r1',
            'deepseek/deepseek-reasoner',
            // OpenAI
            'openai/o1',
            'openai/o1-mini',
            'openai/o1-preview',
            'openai/o3-mini',
            // Google
            'google/gemini-2.0-flash-thinking-exp',
            'google/gemini-2.5-flash-preview',
            'google/gemini-2.5-pro-preview',
            // Anthropic
            'anthropic/claude-sonnet-4',
            'anthropic/claude-3.7-sonnet',
            // xAI Grok (suportam reasoning via parâmetro)
            'x-ai/grok-3',
            'x-ai/grok-3-fast',
            'x-ai/grok-3-mini',
            'x-ai/grok-3-mini-fast',
            'x-ai/grok-4.1',
            'x-ai/grok-4.1-fast',
            'x-ai/grok-4.1-mini',
            'x-ai/grok-4.1-mini-fast',
        ];

        foreach ($reasoningModels as $reasoningModel) {
            if (str_contains($this->model, $reasoningModel) || str_contains($reasoningModel, $this->model)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se o modelo suporta análise de imagens
     */
    protected function supportsVision(): bool
    {
        $visionModels = [
            'openai/gpt-4o',
            'openai/gpt-4-turbo',
            'openai/gpt-4-vision',
            'anthropic/claude-3',
            'anthropic/claude-sonnet-4',
            'google/gemini',
            'meta-llama/llama-3.2',
        ];

        foreach ($visionModels as $visionModel) {
            if (str_contains($this->model, $visionModel) || str_starts_with($this->model, $visionModel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retorna o modelo ideal para uma estratégia de processamento.
     * Permite roteamento de modelos por tipo de documento via config.
     */
    public function getModelForStrategy(string $strategy): string
    {
        $routing = config('services.openrouter.model_routing', []);

        return $routing[$strategy] ?? $routing['default'] ?? $this->model;
    }

    /**
     * Retorna o limite de tokens de saída baseado no modo de reasoning.
     */
    protected function getMaxTokens(bool $useReasoning): int
    {
        if ($useReasoning) {
            return (int) config('services.openrouter.max_tokens_reasoning', 32768);
        }

        return (int) config('services.openrouter.max_tokens', 8192);
    }

    /**
     * Faz a chamada HTTP para a API do OpenRouter
     * Usa chamadas HTTP diretas para evitar problemas de parsing do pacote com respostas de reasoning
     */
    protected function callAPI(string $prompt, bool $deepThinkingEnabled = false, ?string $systemPrompt = null): string
    {
        return $this->withRetry(function () use ($prompt, $deepThinkingEnabled, $systemPrompt) {
            RateLimiterService::apply($this->getRateLimiterKey());

            $useReasoning = $deepThinkingEnabled && $this->supportsReasoning();

            Log::info('OpenRouter API - Iniciando chamada', [
                'model' => $this->model,
                'deep_thinking_requested' => $deepThinkingEnabled,
                'reasoning_enabled' => $useReasoning,
                'prompt_length' => mb_strlen($prompt),
                'has_custom_system_prompt' => $systemPrompt !== null,
            ]);

            $systemContent = $systemPrompt
                ?? 'Você é um assistente jurídico especializado em análise de documentos processuais. Forneça análises objetivas, estruturadas e fundamentadas.';

            $payload = [
                'model' => $this->model,
                'messages' => [
                    $this->buildSystemMessage($systemContent),
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'max_tokens' => $this->getMaxTokens($useReasoning),
            ];

            if (!$useReasoning) {
                $payload['temperature'] = 0.4;
            }

            if ($useReasoning) {
                $payload['reasoning'] = [
                    'enabled' => true,
                    'effort' => 'high',
                    'exclude' => false,
                ];
            }

            return $this->executeAPICall($payload, 'texto', $useReasoning);
        });
    }

    /**
     * Faz chamada à API com uma imagem (multimodal via HTTP direto)
     * Suporta system prompt customizado para prompt caching e reasoning
     */
    protected function callAPIWithImage(string $prompt, string $imageBase64, string $mimetype, bool $deepThinkingEnabled = false, ?string $systemPrompt = null): string
    {
        if (!$this->supportsVision()) {
            Log::warning('OpenRouter: Modelo não suporta visão, usando fallback', [
                'model' => $this->model,
            ]);
            return parent::callAPIWithImage($prompt, $imageBase64, $mimetype, $deepThinkingEnabled, $systemPrompt);
        }

        return $this->withRetry(function () use ($prompt, $imageBase64, $mimetype, $deepThinkingEnabled, $systemPrompt) {
            RateLimiterService::apply($this->getRateLimiterKey());

            $useReasoning = $deepThinkingEnabled && $this->supportsReasoning();

            Log::info('OpenRouter API - Iniciando chamada com imagem', [
                'model' => $this->model,
                'mimetype' => $mimetype,
                'image_size' => strlen($imageBase64),
                'reasoning_enabled' => $useReasoning,
                'has_system_prompt' => $systemPrompt !== null,
            ]);

            $systemContent = $systemPrompt
                ?? 'Você é um assistente jurídico especializado em análise de documentos processuais e imagens. Forneça análises objetivas, estruturadas e fundamentadas.';

            $imageDataUrl = "data:{$mimetype};base64,{$imageBase64}";

            $payload = [
                'model' => $this->model,
                'messages' => [
                    $this->buildSystemMessage($systemContent),
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            ['type' => 'image_url', 'image_url' => ['url' => $imageDataUrl]],
                        ],
                    ],
                ],
                'max_tokens' => $this->getMaxTokens($useReasoning),
            ];

            if (!$useReasoning) {
                $payload['temperature'] = 0.4;
            }

            if ($useReasoning) {
                $payload['reasoning'] = [
                    'enabled' => true,
                    'effort' => 'high',
                    'exclude' => false,
                ];
            }

            return $this->executeAPICall($payload, 'imagem', $useReasoning);
        });
    }

    /**
     * Faz chamada à API com um PDF nativo (via plugin de parsing)
     * Usa pdf-text (grátis) para PDFs textuais e mistral-ocr (pago) para escaneados
     */
    protected function callAPIWithPdf(string $prompt, string $pdfBase64, string $filename, bool $isScanned = false, bool $deepThinkingEnabled = false, ?string $systemPrompt = null): string
    {
        return $this->withRetry(function () use ($prompt, $pdfBase64, $filename, $isScanned, $deepThinkingEnabled, $systemPrompt) {
            RateLimiterService::apply($this->getRateLimiterKey());

            $useReasoning = $deepThinkingEnabled && $this->supportsReasoning();
            $pdfEngine = $isScanned ? 'mistral-ocr' : 'pdf-text';

            Log::info('OpenRouter API - Iniciando chamada com PDF nativo', [
                'model' => $this->model,
                'filename' => $filename,
                'is_scanned' => $isScanned,
                'pdf_engine' => $pdfEngine,
                'pdf_size' => strlen($pdfBase64),
                'reasoning_enabled' => $useReasoning,
            ]);

            $systemContent = $systemPrompt
                ?? 'Você é um assistente jurídico especializado em análise de documentos processuais. Forneça análises objetivas, estruturadas e fundamentadas.';

            $pdfDataUrl = "data:application/pdf;base64,{$pdfBase64}";

            $payload = [
                'model' => $this->model,
                'messages' => [
                    $this->buildSystemMessage($systemContent),
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            ['type' => 'file', 'file' => [
                                'filename' => $filename,
                                'file_data' => $pdfDataUrl,
                            ]],
                        ],
                    ],
                ],
                'max_tokens' => $this->getMaxTokens($useReasoning),
                'plugins' => $this->buildPlugins(
                    [['id' => 'file-parser', 'pdf' => ['engine' => $pdfEngine]]],
                ),
            ];

            if (!$useReasoning) {
                $payload['temperature'] = 0.4;
            }

            if ($useReasoning) {
                $payload['reasoning'] = [
                    'enabled' => true,
                    'effort' => 'high',
                    'exclude' => false,
                ];
            }

            return $this->executeAPICall($payload, "PDF ({$pdfEngine})", $useReasoning);
        });
    }

    /**
     * Faz chamada à API solicitando resposta em JSON estruturado.
     * Usa response_format com json_schema + plugin response-healing.
     */
    protected function callAPIStructured(string $prompt, array $jsonSchema, bool $deepThinkingEnabled = false, ?string $systemPrompt = null): string
    {
        return $this->withRetry(function () use ($prompt, $jsonSchema, $deepThinkingEnabled, $systemPrompt) {
            RateLimiterService::apply($this->getRateLimiterKey());

            $useReasoning = $deepThinkingEnabled && $this->supportsReasoning();

            Log::info('OpenRouter API - Iniciando chamada estruturada (JSON)', [
                'model' => $this->model,
                'schema_name' => $jsonSchema['name'] ?? 'unknown',
                'reasoning_enabled' => $useReasoning,
                'prompt_length' => mb_strlen($prompt),
            ]);

            $systemContent = $systemPrompt
                ?? 'Você é um assistente jurídico especializado em análise de documentos processuais. Forneça análises objetivas, estruturadas e fundamentadas. Responda EXCLUSIVAMENTE no formato JSON solicitado.';

            $payload = [
                'model' => $this->model,
                'messages' => [
                    $this->buildSystemMessage($systemContent),
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'max_tokens' => $this->getMaxTokens($useReasoning),
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => $jsonSchema,
                ],
                'plugins' => $this->buildPlugins(
                    [['id' => 'response-healing']],
                ),
            ];

            if (!$useReasoning) {
                $payload['temperature'] = 0.3;
            }

            if ($useReasoning) {
                $payload['reasoning'] = [
                    'enabled' => true,
                    'effort' => 'high',
                    'exclude' => false,
                ];
            }

            return $this->executeAPICall($payload, 'JSON estruturado', $useReasoning);
        });
    }

    /**
     * Monta o objeto `provider` para routing/fallback/performance da OpenRouter.
     * Configurado via .env: OPENROUTER_PROVIDER_ORDER, OPENROUTER_PROVIDER_SORT, etc.
     */
    private function buildProviderRouting(): ?array
    {
        $provider = [];

        // Ordem de providers (ex: 'anthropic,google,openai')
        $order = config('services.openrouter.provider_order');
        if ($order) {
            $provider['order'] = array_map('trim', explode(',', $order));
        }

        // Permitir fallback automático
        $provider['allow_fallbacks'] = (bool) config('services.openrouter.allow_fallbacks', true);

        // Só roteia para providers que suportam todos os parâmetros
        if (config('services.openrouter.require_parameters', true)) {
            $provider['require_parameters'] = true;
        }

        // Ordenação por critério (price, throughput, latency)
        $sort = config('services.openrouter.provider_sort');
        if ($sort) {
            $provider['sort'] = $sort;
        }

        // Teto de preço por 1M tokens
        $maxPricePrompt = config('services.openrouter.max_price_prompt');
        $maxPriceCompletion = config('services.openrouter.max_price_completion');
        if ($maxPricePrompt || $maxPriceCompletion) {
            $maxPrice = [];
            if ($maxPricePrompt) {
                $maxPrice['prompt'] = (float) $maxPricePrompt;
            }
            if ($maxPriceCompletion) {
                $maxPrice['completion'] = (float) $maxPriceCompletion;
            }
            $provider['max_price'] = $maxPrice;
        }

        return !empty($provider) ? $provider : null;
    }

    /**
     * Monta o array de transforms (ex: middle-out) para o payload.
     */
    private function buildTransforms(): ?array
    {
        $transforms = config('services.openrouter.transforms');

        if ($transforms === '' || $transforms === null || $transforms === false) {
            return null;
        }

        return array_map('trim', explode(',', $transforms));
    }

    /**
     * Monta o array de plugins para o payload.
     * Recebe plugins base (ex: file-parser do PDF) e mescla opcionais.
     */
    private function buildPlugins(array $basePlugins = [], bool $withWebSearch = false): ?array
    {
        $plugins = $basePlugins;

        if ($withWebSearch && config('services.openrouter.web_search_enabled', false)) {
            $plugins[] = [
                'id' => 'web',
                'max_results' => (int) config('services.openrouter.web_search_max_results', 3),
                'search_prompt' => 'Busque legislação, jurisprudência e normas jurídicas brasileiras relevantes para a análise.',
            ];
        }

        return !empty($plugins) ? $plugins : null;
    }

    /**
     * Executa chamada HTTP à API e processa a resposta.
     * Método compartilhado entre callAPI, callAPIWithImage e callAPIWithPdf.
     */
    private function executeAPICall(array $payload, string $callType, bool $useReasoning): string
    {
        // Injeta provider routing (fallbacks, ordenação, teto de preço)
        if (!isset($payload['provider'])) {
            $providerRouting = $this->buildProviderRouting();
            if ($providerRouting) {
                $payload['provider'] = $providerRouting;
            }
        }

        // Injeta transforms (middle-out) se não já definido
        if (!isset($payload['transforms'])) {
            $transforms = $this->buildTransforms();
            if ($transforms !== null) {
                $payload['transforms'] = $transforms;
            }
        }

        $baseUrl = rtrim($this->apiUrl ?? 'https://openrouter.ai/api/v1', '/');
        $chatEndpoint = $baseUrl . '/chat/completions';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'HTTP-Referer' => config('app.url'),
            'X-Title' => config('app.name'),
        ])
            ->timeout($this->timeout)
            ->post($chatEndpoint, $payload);

        if ($response->failed()) {
            $statusCode = $response->status();
            $errorData = $response->json();
            $errorMessage = $errorData['error']['message'] ?? $errorData['message'] ?? 'Erro desconhecido';

            Log::error("OpenRouter API - Erro HTTP ({$callType})", [
                'status' => $statusCode,
                'error' => $errorMessage,
                'model' => $this->model,
            ]);

            throw new \Exception($this->translateError($statusCode, $errorMessage), $statusCode);
        }

        $data = $response->json();

        Log::info("OpenRouter API - Body da resposta ({$callType})", [
            'status' => $response->status(),
            'body_preview' => mb_substr($response->body(), 0, 1000),
            'data_keys' => is_array($data) ? array_keys($data) : 'not_array',
        ]);

        // Extrai informações de uso
        $usage = $data['usage'] ?? null;
        if ($usage) {
            $usageArray = [
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens' => ($usage['prompt_tokens'] ?? 0) + ($usage['completion_tokens'] ?? 0),
            ];

            if (!empty($usage['completion_tokens_details']['reasoning_tokens'])) {
                $usageArray['completion_tokens_details'] = [
                    'reasoning_tokens' => $usage['completion_tokens_details']['reasoning_tokens'],
                ];
            }

            $this->accumulateMetadata($usageArray, $data['model'] ?? $this->model);

            Log::info("OpenRouter API - Resposta recebida ({$callType})", [
                'model' => $data['model'] ?? $this->model,
                'reasoning_enabled' => $useReasoning,
                'usage' => $usageArray,
                'generation_id' => $data['id'] ?? 'N/A',
            ]);
        }

        // Captura annotations para cache (P5)
        $annotations = $data['annotations'] ?? null;
        if ($annotations) {
            $this->lastAnalysisMetadata['file_annotations'] = $annotations;
        }

        // Extrai o texto da resposta
        $text = null;
        $reasoningContent = null;

        if (!empty($data['choices'])) {
            $choice = $data['choices'][0];
            $message = $choice['message'] ?? null;

            if ($message) {
                if ($useReasoning) {
                    Log::info("OpenRouter - Estrutura da mensagem ({$callType})", [
                        'message_keys' => array_keys($message),
                        'content_length' => mb_strlen($message['content'] ?? ''),
                        'has_reasoning' => isset($message['reasoning']),
                    ]);
                }

                $text = $message['content'] ?? null;

                if (is_array($text)) {
                    $textParts = array_filter($text, fn($part) => is_array($part) && ($part['type'] ?? '') === 'text');
                    $text = implode("\n", array_map(fn($part) => $part['text'] ?? '', $textParts));
                }

                $reasoningContent = $message['reasoning'] ?? null;

                if (empty($text) && !empty($reasoningContent)) {
                    Log::info("OpenRouter - Content vazio, usando reasoning ({$callType})", [
                        'reasoning_length' => mb_strlen($reasoningContent),
                    ]);
                    $text = $reasoningContent;
                    $reasoningContent = null;
                }
            }
        }

        if ($reasoningContent) {
            Log::info("OpenRouter - Reasoning separado recebido ({$callType})", [
                'reasoning_length' => mb_strlen($reasoningContent),
            ]);
        }

        if (empty($text)) {
            Log::error("OpenRouter retornou resposta vazia ({$callType})", [
                'response_id' => $data['id'] ?? 'N/A',
                'model' => $data['model'] ?? $this->model,
                'has_reasoning' => !empty($reasoningContent),
                'choices_count' => count($data['choices'] ?? []),
            ]);
            throw new \Exception("A API OpenRouter retornou uma resposta vazia para {$callType}. Tente novamente em alguns instantes.");
        }

        return $text;
    }

    /**
     * Analisa um único documento retornando JSON estruturado.
     * Usa callAPIStructured com response_format + response-healing plugin.
     */
    public function analyzeSingleDocumentStructured(
        string $prompt,
        string $documentText,
        array $jsonSchema,
        bool $deepThinkingEnabled = false,
        ?string $systemPrompt = null
    ): string {
        $this->resetAnalysisMetadata();

        $fullPrompt = $prompt . "\n\n---\n\n# DOCUMENTO\n\n" . $documentText;

        $result = $this->callAPIStructured($fullPrompt, $jsonSchema, $deepThinkingEnabled, $systemPrompt);

        $this->finalizeMetadata(1);

        return $result;
    }

    /**
     * Analisa texto com web search habilitado (usado no parecer final).
     * Ativa o plugin de web search da OpenRouter para consultar legislação e jurisprudência.
     */
    public function analyzeWithWebSearch(
        string $prompt,
        string $documentText,
        bool $deepThinkingEnabled = false
    ): string {
        if (!config('services.openrouter.web_search_enabled', false)) {
            return $this->analyzeSingleDocument($prompt, $documentText, $deepThinkingEnabled);
        }

        $this->resetAnalysisMetadata();

        $fullPrompt = $prompt . "\n\n---\n\n# DOCUMENTO\n\n" . $documentText;

        $result = $this->withRetry(function () use ($fullPrompt, $deepThinkingEnabled) {
            RateLimiterService::apply($this->getRateLimiterKey());

            $useReasoning = $deepThinkingEnabled && $this->supportsReasoning();

            Log::info('OpenRouter API - Iniciando chamada com web search', [
                'model' => $this->model,
                'reasoning_enabled' => $useReasoning,
                'prompt_length' => mb_strlen($fullPrompt),
            ]);

            $systemContent = 'Você é um assistente jurídico especializado em análise de documentos processuais. '
                . 'Forneça análises objetivas, estruturadas e fundamentadas. '
                . 'Ao citar legislação ou jurisprudência, indique a fonte e verifique se está atualizada.';

            $payload = [
                'model' => $this->model,
                'messages' => [
                    $this->buildSystemMessage($systemContent),
                    [
                        'role' => 'user',
                        'content' => $fullPrompt,
                    ],
                ],
                'max_tokens' => $this->getMaxTokens($useReasoning),
                'plugins' => $this->buildPlugins([], true),
            ];

            if (!$useReasoning) {
                $payload['temperature'] = 0.4;
            }

            if ($useReasoning) {
                $payload['reasoning'] = [
                    'enabled' => true,
                    'effort' => 'high',
                    'exclude' => false,
                ];
            }

            return $this->executeAPICall($payload, 'texto+websearch', $useReasoning);
        });

        $this->finalizeMetadata(1);

        return $result;
    }

    /**
     * Monta a mensagem system para o payload da API.
     * Para modelos Anthropic, usa content blocks com cache_control para habilitar prompt caching.
     * Para outros modelos, usa formato padrão (auto-caching pelo provider).
     */
    private function buildSystemMessage(string $content): array
    {
        // Para modelos Anthropic via OpenRouter, formata com cache_control
        // Isso habilita prompt caching explícito: tokens cacheados custam 10% do preço normal
        if ($this->isAnthropicModel()) {
            return [
                'role' => 'system',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $content,
                        'cache_control' => ['type' => 'ephemeral'],
                    ],
                ],
            ];
        }

        // Para outros modelos, formato padrão (OpenAI, DeepSeek, etc. usam auto-caching)
        return [
            'role' => 'system',
            'content' => $content,
        ];
    }

    /**
     * Verifica se o modelo atual é um modelo Anthropic
     */
    private function isAnthropicModel(): bool
    {
        return str_starts_with($this->model, 'anthropic/');
    }

    /**
     * Traduz erros técnicos da API OpenRouter para mensagens amigáveis
     */
    protected function translateError(int $statusCode, string $technicalMessage): string
    {
        $lowerMessage = strtolower($technicalMessage);

        // Erro de créditos insuficientes
        if ($statusCode === 402 || str_contains($lowerMessage, 'insufficient') || str_contains($lowerMessage, 'credit')) {
            return 'Créditos insuficientes na conta OpenRouter. Adicione créditos em https://openrouter.ai/credits';
        }

        // Erros de quota/rate limit
        if ($statusCode === 429) {
            if (str_contains($lowerMessage, 'quota')) {
                return 'Limite de uso da API OpenRouter excedido. Por favor, verifique seu plano ou aguarde.';
            }
            return 'Muitas requisições simultâneas no OpenRouter. Por favor, aguarde alguns segundos e tente novamente.';
        }

        // Erros de autenticação
        if ($statusCode === 401 || $statusCode === 403) {
            return 'Chave de API OpenRouter inválida ou sem permissões. Verifique a configuração OPENROUTER_API_KEY no arquivo .env';
        }

        // Erros de tamanho de conteúdo
        if ($statusCode === 413 || str_contains($lowerMessage, 'too large') || str_contains($lowerMessage, 'too long') || str_contains($lowerMessage, 'context')) {
            return 'O documento é muito grande para o modelo processar. Tente enviar menos documentos por vez ou use um modelo com maior contexto.';
        }

        // Timeout
        if ($statusCode === 504 || str_contains($lowerMessage, 'timeout')) {
            return 'A análise no OpenRouter demorou muito tempo. Tente novamente com documentos menores.';
        }

        // Erro de modelo não encontrado
        if ($statusCode === 404 || str_contains($lowerMessage, 'model not found') || str_contains($lowerMessage, 'not available')) {
            return "Modelo '{$this->model}' não encontrado ou indisponível no OpenRouter. Verifique a configuração OPENROUTER_MODEL no .env";
        }

        // Erro de moderação de conteúdo
        if (str_contains($lowerMessage, 'content') && (str_contains($lowerMessage, 'moderation') || str_contains($lowerMessage, 'policy') || str_contains($lowerMessage, 'blocked'))) {
            return 'O conteúdo foi bloqueado pelos filtros de segurança do modelo. Tente com outros documentos.';
        }

        // Erro genérico do servidor
        if ($statusCode >= 500) {
            return "Erro temporário no servidor OpenRouter (código {$statusCode}). Tente novamente em alguns minutos.";
        }

        return "Erro na API OpenRouter: " . substr($technicalMessage, 0, 150);
    }
}
