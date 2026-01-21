<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeepSeekService extends AbstractAIService
{
    public function __construct()
    {
        $this->apiKey = config('services.deepseek.api_key');
        $this->apiUrl = config('services.deepseek.api_url', 'https://api.deepseek.com/v1');
        $this->model = config('services.deepseek.model', 'deepseek-chat');

        if (empty($this->apiKey)) {
            throw new \Exception('DEEPSEEK_API_KEY não configurado no .env');
        }
    }

    /**
     * Retorna o nome do rate limiter para este provider
     */
    protected function getRateLimiterKey(): string
    {
        return 'deepseek';
    }

    /**
     * Retorna o nome do provider
     */
    public function getName(): string
    {
        return 'DeepSeek';
    }

    /**
     * Valida se a API está acessível
     */
    public function healthCheck(): bool
    {
        try {
            $url = "{$this->apiUrl}/chat/completions";

            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($url, [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'user', 'content' => 'Hello']
                    ],
                    'max_tokens' => 10,
                ]);

            return $response->successful();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Faz a chamada HTTP para a API do DeepSeek com exponential backoff para rate limiting
     * DeepSeek usa a mesma interface da OpenAI (chat completions)
     */
    protected function callAPI(string $prompt, bool $deepThinkingEnabled = false): string
    {
        return $this->withRetry(function () use ($prompt, $deepThinkingEnabled) {
            // Aplica rate limiting antes da chamada
            RateLimiterService::apply($this->getRateLimiterKey());

            $url = "{$this->apiUrl}/chat/completions";

            // Determina o modelo correto baseado no deep thinking e configuração
            $modelToUse = $this->model;
            $useThinkingMode = false;

            // Verifica se o modelo configurado é reasoner (requer thinking mode)
            $isReasonerModel = str_contains($this->model, 'reasoner');

            if ($isReasonerModel) {
                // Modelo reasoner SEMPRE usa thinking mode (é obrigatório para funcionar)
                $useThinkingMode = true;
                Log::info('DeepSeek Reasoner detectado - ativando thinking mode obrigatório', [
                    'model' => $modelToUse,
                    'deep_thinking_param' => $deepThinkingEnabled,
                    'timeout' => '600s'
                ]);
            } elseif ($deepThinkingEnabled) {
                // Deep thinking solicitado mas modelo não suporta
                Log::info('Deep Thinking solicitado mas modelo não suporta thinking mode', [
                    'model' => $modelToUse,
                    'info' => 'Para usar thinking mode, configure DEEPSEEK_MODEL=deepseek-reasoner'
                ]);
            } else {
                // Modelo normal sem thinking mode
                Log::info('Usando modelo DeepSeek sem thinking mode', [
                    'model' => $modelToUse
                ]);
            }

            // Monta o corpo da requisição
            $requestBody = [
                'model' => $modelToUse,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Você é um assistente jurídico especializado em análise de documentos processuais. Forneça análises objetivas, estruturadas e fundamentadas.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => $useThinkingMode ? 16384 : 4096, // Thinking mode pode gerar respostas maiores
                'stream' => false,
            ];

            // Adiciona parâmetros condicionais baseado no modo
            if ($useThinkingMode) {
                // Thinking mode: ativa o pensamento profundo
                $requestBody['thinking'] = ['type' => 'enabled'];
            } else {
                // Modo normal: pode usar temperature
                $requestBody['temperature'] = config('services.deepseek.temperature', 0.4);
            }

            // Timeout maior para modo de pensamento profundo (reasoning)
            $timeout = $useThinkingMode ? 600 : 300;

            $response = Http::timeout($timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $requestBody);

            if (!$response->successful()) {
                $statusCode = $response->status();
                $errorBody = $response->json();
                $errorMessage = $errorBody['error']['message'] ?? $errorBody['message'] ?? 'Erro desconhecido';

                throw new \Exception($this->translateError($statusCode, $errorMessage), $statusCode);
            }

            $data = $response->json();

            // Log detalhado da resposta com informações de uso
            $usage = $data['usage'] ?? [];
            Log::info('DeepSeek API - Resposta recebida', [
                'model' => $data['model'] ?? $modelToUse,
                'deep_thinking' => $useThinkingMode,
                'finish_reason' => $data['choices'][0]['finish_reason'] ?? 'unknown',
                'usage' => [
                    'prompt_tokens' => $usage['prompt_tokens'] ?? 'N/A',
                    'completion_tokens' => $usage['completion_tokens'] ?? 'N/A',
                    'total_tokens' => $usage['total_tokens'] ?? 'N/A',
                    'reasoning_tokens' => $usage['completion_tokens_details']['reasoning_tokens'] ?? 'N/A',
                ],
                'response_id' => $data['id'] ?? 'N/A',
                'created_at' => isset($data['created']) ? date('Y-m-d H:i:s', $data['created']) : 'N/A',
            ]);

            // Acumula metadados da resposta
            $this->accumulateMetadata($usage, $data['model'] ?? $modelToUse);

            // Extrai o texto da resposta
            $message = $data['choices'][0]['message'] ?? [];
            $text = $message['content'] ?? null;
            $reasoningContent = $message['reasoning_content'] ?? null;

            // Log para debug do thinking mode
            if ($useThinkingMode) {
                Log::info('DeepSeek Thinking Mode - Estrutura da resposta', [
                    'has_content' => !empty($text),
                    'content_length' => $text ? mb_strlen($text) : 0,
                    'has_reasoning_content' => !empty($reasoningContent),
                    'reasoning_content_length' => $reasoningContent ? mb_strlen($reasoningContent) : 0,
                    'message_keys' => array_keys($message),
                ]);
            }

            if (empty($text)) {
                Log::error('DeepSeek retornou resposta vazia', [
                    'response_data' => $data,
                    'status_code' => $response->status(),
                    'model' => $data['model'] ?? $modelToUse,
                    'thinking_mode' => $useThinkingMode,
                    'has_reasoning_content' => !empty($reasoningContent),
                    'raw_message' => $message,
                ]);
                throw new \Exception('A API retornou uma resposta vazia. Tente novamente em alguns instantes.');
            }

            return $text;
        });
    }

    /**
     * Traduz erros técnicos da API DeepSeek para mensagens amigáveis
     */
    protected function translateError(int $statusCode, string $technicalMessage): string
    {
        // Erro de saldo insuficiente
        if ($statusCode === 402 || str_contains(strtolower($technicalMessage), 'insufficient balance')) {
            return 'Saldo insuficiente na conta DeepSeek. Adicione créditos em https://platform.deepseek.com/top_up ou utilize o Google Gemini temporariamente.';
        }

        // Erros de quota/rate limit
        if ($statusCode === 429) {
            if (str_contains(strtolower($technicalMessage), 'quota')) {
                return 'Limite de uso da API DeepSeek excedido. Por favor, verifique seu plano ou aguarde para novas análises.';
            }
            return 'Muitas requisições simultâneas no DeepSeek. Por favor, aguarde alguns segundos e tente novamente.';
        }

        // Erros de autenticação
        if ($statusCode === 401 || $statusCode === 403) {
            return 'Chave de API DeepSeek inválida ou sem permissões. Verifique a configuração DEEPSEEK_API_KEY no arquivo .env';
        }

        // Erros de tamanho de conteúdo
        if ($statusCode === 413 || str_contains(strtolower($technicalMessage), 'too large') || str_contains(strtolower($technicalMessage), 'too long')) {
            return 'O documento é muito grande para o DeepSeek processar. Tente enviar menos documentos por vez.';
        }

        // Timeout
        if ($statusCode === 504 || str_contains(strtolower($technicalMessage), 'timeout')) {
            return 'A análise no DeepSeek demorou muito tempo. Tente novamente com documentos menores.';
        }

        // Erro de modelo não encontrado
        if ($statusCode === 404 || str_contains(strtolower($technicalMessage), 'model not found')) {
            return 'Modelo DeepSeek não encontrado. Verifique a configuração DEEPSEEK_MODEL no .env';
        }

        // Erro genérico do servidor
        if ($statusCode >= 500) {
            return "Erro temporário no servidor DeepSeek (código {$statusCode}). Tente novamente em alguns minutos.";
        }

        return "Erro na API DeepSeek: " . substr($technicalMessage, 0, 150);
    }
}
