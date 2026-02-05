<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MoeMizrak\LaravelOpenrouter\DTO\ChatData;
use MoeMizrak\LaravelOpenrouter\DTO\ErrorData;
use MoeMizrak\LaravelOpenrouter\DTO\ImageContentPartData;
use MoeMizrak\LaravelOpenrouter\DTO\ImageUrlData;
use MoeMizrak\LaravelOpenrouter\DTO\MessageData;
use MoeMizrak\LaravelOpenrouter\DTO\TextContentData;
use MoeMizrak\LaravelOpenrouter\Facades\LaravelOpenRouter;
use MoeMizrak\LaravelOpenrouter\Types\RoleType;

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
     * Valida se a API está acessível
     */
    public function healthCheck(): bool
    {
        try {
            $response = LaravelOpenRouter::limitRequest();
            return $response !== null;
        } catch (\Exception) {
            return false;
        }
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
     * Faz a chamada HTTP para a API do OpenRouter
     * Usa chamadas HTTP diretas para evitar problemas de parsing do pacote com respostas de reasoning
     */
    protected function callAPI(string $prompt, bool $deepThinkingEnabled = false): string
    {
        return $this->withRetry(function () use ($prompt, $deepThinkingEnabled) {
            // Aplica rate limiting antes da chamada
            RateLimiterService::apply($this->getRateLimiterKey());

            $useReasoning = $deepThinkingEnabled && $this->supportsReasoning();

            Log::info('OpenRouter API - Iniciando chamada', [
                'model' => $this->model,
                'deep_thinking_requested' => $deepThinkingEnabled,
                'reasoning_enabled' => $useReasoning,
                'prompt_length' => mb_strlen($prompt),
            ]);

            // Monta o payload da requisição
            $payload = [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Você é um assistente jurídico especializado em análise de documentos processuais. Forneça análises objetivas, estruturadas e fundamentadas.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'max_tokens' => $useReasoning ? 16384 : 8192,
            ];

            // Adiciona temperature apenas se não usar reasoning
            if (!$useReasoning) {
                $payload['temperature'] = 0.4;
            }

            // Adiciona reasoning se suportado e solicitado
            if ($useReasoning) {
                $payload['reasoning'] = [
                    'effort' => 'high',
                    'exclude' => false,
                ];
            }

            // Monta a URL completa do endpoint de chat
            $baseUrl = rtrim($this->apiUrl ?? 'https://openrouter.ai/api/v1', '/');
            $chatEndpoint = $baseUrl . '/chat/completions';

            // Faz a chamada HTTP direta
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('app.url'),
                'X-Title' => config('app.name'),
            ])
                ->timeout($this->timeout)
                ->post($chatEndpoint, $payload);

            // Verifica se houve erro HTTP
            if ($response->failed()) {
                $statusCode = $response->status();
                $errorData = $response->json();
                $errorMessage = $errorData['error']['message'] ?? $errorData['message'] ?? 'Erro desconhecido';

                Log::error('OpenRouter API - Erro HTTP', [
                    'status' => $statusCode,
                    'error' => $errorMessage,
                    'model' => $this->model,
                ]);

                throw new \Exception($this->translateError($statusCode, $errorMessage), $statusCode);
            }

            $data = $response->json();

            // Log do body completo para debug
            Log::info('OpenRouter API - Body da resposta', [
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

                // Adiciona reasoning tokens se disponível
                if (!empty($usage['completion_tokens_details']['reasoning_tokens'])) {
                    $usageArray['completion_tokens_details'] = [
                        'reasoning_tokens' => $usage['completion_tokens_details']['reasoning_tokens'],
                    ];
                }

                $this->accumulateMetadata($usageArray, $data['model'] ?? $this->model);

                Log::info('OpenRouter API - Resposta recebida', [
                    'model' => $data['model'] ?? $this->model,
                    'reasoning_enabled' => $useReasoning,
                    'usage' => $usageArray,
                    'generation_id' => $data['id'] ?? 'N/A',
                ]);
            }

            // Extrai o texto da resposta
            $text = null;
            $reasoningContent = null;

            if (!empty($data['choices'])) {
                $choice = $data['choices'][0];
                $message = $choice['message'] ?? null;

                if ($message) {
                    // Log de debug para mensagens com reasoning
                    if ($useReasoning) {
                        Log::info('OpenRouter - Estrutura da mensagem', [
                            'message_keys' => array_keys($message),
                            'content_length' => mb_strlen($message['content'] ?? ''),
                            'has_reasoning' => isset($message['reasoning']),
                            'has_reasoning_details' => isset($message['reasoning_details']),
                        ]);
                    }

                    // Extrai o conteúdo principal
                    $text = $message['content'] ?? null;

                    // Se content for um array, extrai o texto
                    if (is_array($text)) {
                        $textParts = array_filter($text, fn($part) => is_array($part) && ($part['type'] ?? '') === 'text');
                        $text = implode("\n", array_map(fn($part) => $part['text'] ?? '', $textParts));
                    }

                    // Extrai o conteúdo de reasoning (para log)
                    $reasoningContent = $message['reasoning'] ?? null;

                    // Se content vazio mas há reasoning, usa reasoning como fallback
                    if (empty($text) && !empty($reasoningContent)) {
                        Log::info('OpenRouter - Content vazio, usando reasoning como resposta', [
                            'reasoning_length' => mb_strlen($reasoningContent),
                        ]);
                        $text = $reasoningContent;
                        $reasoningContent = null;
                    }
                }
            }

            if ($reasoningContent) {
                Log::info('OpenRouter - Reasoning separado recebido', [
                    'reasoning_length' => mb_strlen($reasoningContent),
                ]);
            }

            if (empty($text)) {
                Log::error('OpenRouter retornou resposta vazia', [
                    'response_id' => $data['id'] ?? 'N/A',
                    'model' => $data['model'] ?? $this->model,
                    'has_reasoning' => !empty($reasoningContent),
                    'reasoning_enabled' => $useReasoning,
                    'choices_count' => count($data['choices'] ?? []),
                    'raw_message' => json_encode($data['choices'][0]['message'] ?? [], JSON_UNESCAPED_UNICODE),
                ]);
                throw new \Exception('A API OpenRouter retornou uma resposta vazia. Tente novamente em alguns instantes.');
            }

            return $text;
        });
    }

    /**
     * Faz chamada à API com uma imagem (multimodal)
     */
    protected function callAPIWithImage(string $prompt, string $imageBase64, string $mimetype, bool $deepThinkingEnabled = false): string
    {
        if (!$this->supportsVision()) {
            Log::warning('OpenRouter: Modelo não suporta visão, usando fallback', [
                'model' => $this->model
            ]);
            return parent::callAPIWithImage($prompt, $imageBase64, $mimetype, $deepThinkingEnabled);
        }

        return $this->withRetry(function () use ($prompt, $imageBase64, $mimetype, $deepThinkingEnabled) {
            // Aplica rate limiting antes da chamada
            RateLimiterService::apply($this->getRateLimiterKey());

            Log::info('OpenRouter API - Iniciando chamada com imagem', [
                'model' => $this->model,
                'mimetype' => $mimetype,
                'image_size' => strlen($imageBase64),
            ]);

            // Cria o data URL da imagem
            $imageDataUrl = "data:{$mimetype};base64,{$imageBase64}";

            // Monta as mensagens com conteúdo multimodal
            $content = [
                new TextContentData(
                    type: 'text',
                    text: $prompt
                ),
                new ImageContentPartData(
                    type: 'image_url',
                    image_url: new ImageUrlData(
                        url: $imageDataUrl
                    )
                ),
            ];

            $messages = [
                new MessageData(
                    content: 'Você é um assistente jurídico especializado em análise de documentos processuais e imagens. Forneça análises objetivas, estruturadas e fundamentadas.',
                    role: RoleType::SYSTEM
                ),
                new MessageData(
                    content: $content,
                    role: RoleType::USER
                ),
            ];

            // Monta o request
            $chatData = new ChatData(
                messages: $messages,
                model: $this->model,
                max_tokens: 8192,
                temperature: 0.4,
                usage: true,
            );

            $response = LaravelOpenRouter::chatRequest($chatData);

            // Verifica se é um erro
            if ($response instanceof ErrorData) {
                $statusCode = $response->code ?? 500;
                $errorMessage = $response->message ?? 'Erro desconhecido';

                throw new \Exception($this->translateError($statusCode, $errorMessage), $statusCode);
            }

            // Extrai informações de uso
            $usage = $response->usage ?? null;
            if ($usage) {
                $this->accumulateMetadata([
                    'prompt_tokens' => $usage->prompt_tokens ?? 0,
                    'completion_tokens' => $usage->completion_tokens ?? 0,
                    'total_tokens' => ($usage->prompt_tokens ?? 0) + ($usage->completion_tokens ?? 0),
                ], $response->model ?? $this->model);

                Log::info('OpenRouter API - Resposta de imagem recebida', [
                    'model' => $response->model ?? $this->model,
                    'usage' => [
                        'prompt_tokens' => $usage->prompt_tokens ?? 'N/A',
                        'completion_tokens' => $usage->completion_tokens ?? 'N/A',
                    ],
                ]);
            }

            // Extrai o texto da resposta
            $text = null;
            if (!empty($response->choices)) {
                $choice = $response->choices[0];
                $message = $choice->message ?? null;

                if ($message) {
                    $text = $message->content ?? null;
                }
            }

            if (empty($text)) {
                Log::error('OpenRouter retornou resposta vazia para imagem', [
                    'response_id' => $response->id ?? 'N/A',
                    'model' => $response->model ?? $this->model,
                ]);
                throw new \Exception('A API OpenRouter retornou uma resposta vazia para a imagem. Tente novamente.');
            }

            return $text;
        });
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
