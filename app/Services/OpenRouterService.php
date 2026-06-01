<?php

namespace App\Services;

use App\Models\AiModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\StatusCode;

class OpenRouterService extends AbstractAIService
{
    private const OBSERVABILITY_TEXT_LIMIT = 4000;

    protected ?OpenRouterResponseHandler $responseHandler = null;
    protected ?OpenRouterPayloadEnricher $payloadEnricher = null;

    public function __construct()
    {
        $this->apiKey = config('services.openrouter.api_key') ?? config('laravel-openrouter.api_key');
        $this->apiUrl = config('services.openrouter.api_url') ?? config('laravel-openrouter.api_endpoint');
        $this->model = ''; // Modelo será definido via setModel() a partir do cadastro de prompts (AiPrompt → AiModel)
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
     * Verifica se o modelo suporta reasoning (pensamento profundo).
     * Consulta o cadastro de modelos no banco de dados.
     */
    protected function supportsReasoning(): bool
    {
        if (empty($this->model)) {
            return false;
        }

        $aiModel = AiModel::where('model_id', $this->model)
            ->where('is_active', true)
            ->first();

        return $aiModel?->supports_reasoning ?? false;
    }

    /**
     * Verifica se o modelo suporta análise de imagens.
     * Consulta o cadastro de modelos no banco de dados.
     */
    protected function supportsVision(): bool
    {
        if (empty($this->model)) {
            return false;
        }

        $aiModel = AiModel::where('model_id', $this->model)
            ->where('is_active', true)
            ->first();

        return $aiModel?->supports_vision ?? false;
    }

    /**
     * Retorna o modelo ideal para uma estratégia de processamento.
     * Usa exclusivamente o cadastro no banco de dados (AiModel com purpose).
     * Retorna o modelo atual se nenhum modelo específico estiver cadastrado para a estratégia.
     */
    public function getModelForStrategy(string $strategy): string
    {
        $dbModel = AiModel::getModelIdForPurpose($strategy);

        return $dbModel ?? $this->model;
    }

    /**
     * Retorna o limite de tokens de saída baseado no modo de reasoning.
     */
    protected function getMaxTokens(bool $useReasoning): int
    {
        if ($this->maxTokensOverride !== null) {
            return $this->maxTokensOverride;
        }

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
        return $this->resolveLlmResponseCache(
            'texto',
            [
                'prompt' => $prompt,
                'system_prompt' => $systemPrompt,
                'deep_thinking' => $deepThinkingEnabled,
            ],
            $deepThinkingEnabled,
            function () use ($prompt, $deepThinkingEnabled, $systemPrompt) {
                return $this->withRetry(function () use ($prompt, $deepThinkingEnabled, $systemPrompt) {
                    RateLimiterService::apply($this->getRateLimiterKey());

                    $this->ensureModelIsConfigured();

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

                    return $this->executeAPICall($payload, 'texto', $useReasoning);
                });
            }
        );
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
            $this->ensureModelIsConfigured();
            return parent::callAPIWithImage($prompt, $imageBase64, $mimetype, $deepThinkingEnabled, $systemPrompt);
        }

        return $this->resolveLlmResponseCache(
            'imagem',
            [
                'prompt' => $prompt,
                'system_prompt' => $systemPrompt,
                'mimetype' => $mimetype,
                'image_hash' => hash('sha256', $imageBase64),
                'deep_thinking' => $deepThinkingEnabled,
            ],
            $deepThinkingEnabled,
            function () use ($prompt, $imageBase64, $mimetype, $deepThinkingEnabled, $systemPrompt) {
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

                    return $this->executeAPICall($payload, 'imagem', $useReasoning);
                });
            }
        );
    }

    /**
     * Faz chamada à API com um PDF nativo (via plugin de parsing)
     * Usa pdf-text (grátis) para PDFs textuais e mistral-ocr (pago) para escaneados
     */
    protected function callAPIWithPdf(string $prompt, string $pdfBase64, string $filename, bool $isScanned = false, bool $deepThinkingEnabled = false, ?string $systemPrompt = null): string
    {
        return $this->resolveLlmResponseCache(
            'pdf',
            [
                'prompt' => $prompt,
                'system_prompt' => $systemPrompt,
                'filename' => $filename,
                'is_scanned' => $isScanned,
                'pdf_hash' => hash('sha256', $pdfBase64),
                'deep_thinking' => $deepThinkingEnabled,
            ],
            $deepThinkingEnabled,
            function () use ($prompt, $pdfBase64, $filename, $isScanned, $deepThinkingEnabled, $systemPrompt) {
                return $this->withRetry(function () use ($prompt, $pdfBase64, $filename, $isScanned, $deepThinkingEnabled, $systemPrompt) {
                    RateLimiterService::apply($this->getRateLimiterKey());
                    $this->ensureModelIsConfigured();

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

                    return $this->executeAPICall($payload, "PDF ({$pdfEngine})", $useReasoning);
                });
            }
        );
    }

    /**
     * Faz chamada à API solicitando resposta em JSON estruturado.
     * Usa response_format com json_schema + plugin response-healing.
     */
    protected function callAPIStructured(string $prompt, array $jsonSchema, bool $deepThinkingEnabled = false, ?string $systemPrompt = null): string
    {
        return $this->resolveLlmResponseCache(
            'json_estruturado',
            [
                'prompt' => $prompt,
                'system_prompt' => $systemPrompt,
                'schema_hash' => hash('sha256', json_encode($jsonSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'deep_thinking' => $deepThinkingEnabled,
            ],
            $deepThinkingEnabled,
            function () use ($prompt, $jsonSchema, $deepThinkingEnabled, $systemPrompt) {
                return $this->withRetry(function () use ($prompt, $jsonSchema, $deepThinkingEnabled, $systemPrompt) {
                    RateLimiterService::apply($this->getRateLimiterKey());

                    $this->ensureModelIsConfigured();

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

                    return $this->executeAPICall($payload, 'JSON estruturado', $useReasoning);
                });
            }
        );
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
        if (empty($payload['model'])) {
            throw new \RuntimeException('OpenRouter: modelo não definido no payload. A aplicação deve definir o modelo vinculado ao prompt antes da chamada.');
        }

        $start = hrtime(true);
        $metrics = app(OtelMetricsService::class);
        $span = null;
        $scope = null;

        if (class_exists(Globals::class)) {
            try {
                $tracer = Globals::tracerProvider()->getTracer('painel-laravel-ai');
                $span = $tracer->spanBuilder('ai.api.call')->startSpan();
                $scope = $span->activate();
            } catch (\Throwable) {
                $span = null;
                $scope = null;
            }
        }

        if ($span !== null) {
            $span->setAttribute('ai.provider', 'OpenRouter');
            $span->setAttribute('ai.model', (string) ($payload['model'] ?? $this->model));
            $span->setAttribute('ai.call_type', $callType);
            $span->setAttribute('ai.reasoning_enabled', $useReasoning);

            // GenAI semantic conventions + Langfuse-specific hints.
            $span->setAttribute('gen_ai.system', 'openrouter');
            $span->setAttribute('gen_ai.request.model', (string) ($payload['model'] ?? $this->model));
            $span->setAttribute('langfuse.observation.type', 'generation');
            $span->setAttribute('langfuse.trace.name', 'openrouter.' . strtolower(str_replace(' ', '_', $callType)));

            $promptInput = $this->extractPromptInput($payload);
            if ($promptInput !== '') {
                $span->setAttribute('langfuse.observation.input', $this->truncateForObservability($promptInput));
            }

            $analysisContext = $this->getAnalysisContext();
            if (!empty($analysisContext['user_id'])) {
                $span->setAttribute('langfuse.user.id', (string) $analysisContext['user_id']);
            }
            if (!empty($analysisContext['session_id'])) {
                $span->setAttribute('langfuse.session.id', (string) $analysisContext['session_id']);
            }
            if (!empty($analysisContext['trace_id'])) {
                $span->setAttribute('langfuse.trace.id', (string) $analysisContext['trace_id']);
            }
            if (!empty($analysisContext['entity'])) {
                $span->setAttribute('langfuse.entity', (string) $analysisContext['entity']);
            }
            if (!empty($analysisContext['entity_id'])) {
                $span->setAttribute('langfuse.entity_id', (string) $analysisContext['entity_id']);
            }
        }

        try {
            $payload = $this->getPayloadEnricher()->enrich($payload, $useReasoning, $this->temperatureOverride);

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

                if ($span !== null) {
                    $span->setAttribute('http.response.status_code', $statusCode);
                    $span->setStatus(StatusCode::STATUS_ERROR, $errorMessage);
                }

                throw new \Exception($this->translateError($statusCode, $errorMessage), $statusCode);
            }

            $data = $response->json();

            Log::info("OpenRouter API - Body da resposta ({$callType})", [
                'status' => $response->status(),
                'body_preview' => mb_substr($response->body(), 0, 1000),
                'data_keys' => is_array($data) ? array_keys($data) : 'not_array',
            ]);

            $parsedResponse = $this->getResponseHandler()->parse($data, $callType, $useReasoning);
            $usageArray = $parsedResponse['usage'];
            $totalTokens = (int) ($parsedResponse['total_tokens'] ?? 0);
            $text = $parsedResponse['text'];

            if (is_array($usageArray)) {
                $model = $parsedResponse['model'] ?? $this->model;
                $this->accumulateMetadata($usageArray, $model);

                if ($span !== null) {
                    $span->setAttribute('ai.prompt_tokens', (int) ($usageArray['prompt_tokens'] ?? 0));
                    $span->setAttribute('ai.completion_tokens', (int) ($usageArray['completion_tokens'] ?? 0));
                    $span->setAttribute('ai.total_tokens', $totalTokens);

                    $span->setAttribute('gen_ai.usage.input_tokens', (int) ($usageArray['prompt_tokens'] ?? 0));
                    $span->setAttribute('gen_ai.usage.output_tokens', (int) ($usageArray['completion_tokens'] ?? 0));
                    $span->setAttribute('gen_ai.usage.total_tokens', $totalTokens);
                    $span->setAttribute('gen_ai.response.model', (string) ($model ?? $this->model));

                    $generationId = $parsedResponse['generation_id'] ?? null;
                    if (is_string($generationId) && $generationId !== '') {
                        $span->setAttribute('gen_ai.response.id', $generationId);
                    }

                    if ($text !== '') {
                        $span->setAttribute('langfuse.observation.output', $this->truncateForObservability($text));
                    }
                }

                Log::info("OpenRouter API - Resposta recebida ({$callType})", [
                    'model' => $model,
                    'reasoning_enabled' => $useReasoning,
                    'usage' => $usageArray,
                    'generation_id' => $parsedResponse['generation_id'] ?? 'N/A',
                ]);
            }

            if (!empty($parsedResponse['annotations']) && is_array($parsedResponse['annotations'])) {
                $this->lastAnalysisMetadata['file_annotations'] = $parsedResponse['annotations'];
            }

            $durationMs = (hrtime(true) - $start) / 1_000_000;
            $metrics->recordAiApiCall('OpenRouter', (string) ($payload['model'] ?? $this->model), $callType, 'success', $durationMs, $totalTokens);

            if ($span !== null) {
                $span->setStatus(StatusCode::STATUS_OK);
            }

            return $text;
        } catch (\Throwable $exception) {
            $durationMs = (hrtime(true) - $start) / 1_000_000;
            $metrics->recordAiApiCall('OpenRouter', (string) ($payload['model'] ?? $this->model), $callType, 'failed', $durationMs);

            if ($span !== null) {
                $span->recordException($exception);
                $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
            }

            throw $exception;
        } finally {
            if ($scope !== null) {
                $scope->detach();
            }

            if ($span !== null) {
                $span->end();
            }
        }
    }

    private function extractPromptInput(array $payload): string
    {
        $messages = $payload['messages'] ?? null;

        if (!is_array($messages)) {
            return '';
        }

        $parts = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = is_string($message['role'] ?? null) ? $message['role'] : 'unknown';
            $content = $message['content'] ?? null;

            if (is_string($content)) {
                $parts[] = "[{$role}] {$content}";
                continue;
            }

            if (!is_array($content)) {
                continue;
            }

            $contentParts = [];
            foreach ($content as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $type = $item['type'] ?? null;
                if ($type === 'text' && is_string($item['text'] ?? null)) {
                    $contentParts[] = $item['text'];
                } elseif ($type === 'image_url') {
                    $contentParts[] = '[image_url omitted]';
                } elseif ($type === 'file') {
                    $filename = is_string($item['file']['filename'] ?? null) ? $item['file']['filename'] : 'file';
                    $contentParts[] = "[file: {$filename}]";
                }
            }

            if (!empty($contentParts)) {
                $parts[] = "[{$role}] " . implode("\n", $contentParts);
            }
        }

        return implode("\n\n", $parts);
    }

    private function truncateForObservability(string $value): string
    {
        if (mb_strlen($value) <= self::OBSERVABILITY_TEXT_LIMIT) {
            return $value;
        }

        return mb_substr($value, 0, self::OBSERVABILITY_TEXT_LIMIT) . '... [truncated]';
    }

    /**
     * Resolve resposta da IA com cache opcional por hash de input.
     */
    private function resolveLlmResponseCache(string $callType, array $fingerprint, bool $deepThinkingEnabled, callable $resolver): string
    {
        if (!$this->shouldUseLlmCache($deepThinkingEnabled)) {
            return (string) $resolver();
        }

        $cacheKey = $this->buildLlmCacheKey($callType, $fingerprint);
        $cacheStoreName = (string) config('analysis.llm_cache.store', 'redis');
        $ttlSeconds = (int) config('analysis.llm_cache.ttl_seconds', 604800);

        try {
            $cacheStore = Cache::store($cacheStoreName);
        } catch (\Throwable $e) {
            Log::warning('OpenRouter API - Store de cache indisponível, usando store padrão', [
                'requested_store' => $cacheStoreName,
                'error' => $e->getMessage(),
            ]);

            $cacheStore = Cache::store();
        }

        if ($cacheStore->has($cacheKey)) {
            Log::info('OpenRouter API - Cache hit', [
                'model' => $this->model,
                'call_type' => $callType,
                'cache_key' => $cacheKey,
            ]);

            return (string) $cacheStore->get($cacheKey);
        }

        $result = (string) $resolver();

        if ($ttlSeconds > 0) {
            $cacheStore->put($cacheKey, $result, now()->addSeconds($ttlSeconds));
        }

        Log::info('OpenRouter API - Cache miss', [
            'model' => $this->model,
            'call_type' => $callType,
            'cache_key' => $cacheKey,
            'ttl_seconds' => $ttlSeconds,
        ]);

        return $result;
    }

    /**
     * Define se o cache LLM pode ser aplicado para a chamada atual.
     */
    private function shouldUseLlmCache(bool $deepThinkingEnabled): bool
    {
        if (!(bool) config('analysis.llm_cache.enabled', true)) {
            return false;
        }

        if ($deepThinkingEnabled) {
            return false;
        }

        $temperature = $this->temperatureOverride;
        if ($temperature === null) {
            $temperature = (float) config('services.openrouter.temperature', 0.3);
        }

        $maxTemperature = (float) config('analysis.llm_cache.max_temperature', 0.2);

        return $temperature <= $maxTemperature;
    }

    /**
     * Monta chave estável para cache LLM com base no input efetivo da chamada.
     */
    private function buildLlmCacheKey(string $callType, array $fingerprint): string
    {
        $context = [
            'provider' => 'openrouter',
            'call_type' => $callType,
            'model' => $this->model,
            'temperature' => $this->temperatureOverride ?? (float) config('services.openrouter.temperature', 0.3),
            'max_tokens_override' => $this->maxTokensOverride,
            'input_char_limit' => $this->inputCharLimit,
            'fingerprint' => $fingerprint,
        ];

        return 'llm:openrouter:' . hash('sha256', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function getResponseHandler(): OpenRouterResponseHandler
    {
        if ($this->responseHandler !== null) {
            return $this->responseHandler;
        }

        try {
            $this->responseHandler = app(OpenRouterResponseHandler::class);
        } catch (\Throwable) {
            $this->responseHandler = new OpenRouterResponseHandler();
        }

        return $this->responseHandler;
    }

    private function getPayloadEnricher(): OpenRouterPayloadEnricher
    {
        if ($this->payloadEnricher !== null) {
            return $this->payloadEnricher;
        }

        try {
            $this->payloadEnricher = app(OpenRouterPayloadEnricher::class);
        } catch (\Throwable) {
            $this->payloadEnricher = new OpenRouterPayloadEnricher([
                'temperature' => config('services.openrouter.temperature', 0.3),
                'provider_order' => config('services.openrouter.provider_order'),
                'allow_fallbacks' => config('services.openrouter.allow_fallbacks', true),
                'require_parameters' => config('services.openrouter.require_parameters', true),
                'provider_sort' => config('services.openrouter.provider_sort'),
                'max_price_prompt' => config('services.openrouter.max_price_prompt'),
                'max_price_completion' => config('services.openrouter.max_price_completion'),
                'transforms' => config('services.openrouter.transforms'),
            ]);
        }

        return $this->payloadEnricher;
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

            $this->ensureModelIsConfigured();

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

            return $this->executeAPICall($payload, 'texto+websearch', $useReasoning);
        });

        $this->finalizeMetadata(1);

        return $result;
    }

    /**
     * Garante que o modelo foi explicitamente definido pela aplicação.
     */
    private function ensureModelIsConfigured(): void
    {
        if (blank($this->model)) {
            throw new \RuntimeException(
                'OpenRouter: nenhum modelo foi definido na aplicação. Configure um AiModel vinculado ao prompt antes de executar a análise.'
            );
        }
    }

    /**
     * Monta a mensagem system para o payload da API.
     * Usa content blocks com cache_control para habilitar prompt caching.
     * O cache_control só é incluído para modelos Anthropic, pois é um parâmetro
     * específico desse provider e causa erro 404 com require_parameters=true
     * em outros providers (OpenAI, DeepSeek, etc.).
     */
    private function buildSystemMessage(string $content): array
    {
        $contentBlock = [
            'type' => 'text',
            'text' => $content,
        ];

        // cache_control é específico da Anthropic — só incluir para modelos Claude
        if ($this->isAnthropicModel()) {
            $contentBlock['cache_control'] = ['type' => 'ephemeral'];
        }

        return [
            'role' => 'system',
            'content' => [$contentBlock],
        ];
    }

    /**
     * Verifica se o modelo atual é da Anthropic (Claude).
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
            return "Modelo '{$this->model}' não encontrado ou indisponível no OpenRouter. Verifique o modelo configurado no cadastro de prompts.";
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
