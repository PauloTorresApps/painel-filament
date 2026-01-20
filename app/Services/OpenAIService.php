<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService extends AbstractAIService
{
    /**
     * Limite de caracteres específico para OpenAI (contexto menor que Gemini/DeepSeek)
     */
    protected const TOTAL_PROMPT_CHAR_LIMIT = 500000; // ~125k tokens (GPT-4 tem 128k context window)

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
        $this->apiUrl = config('services.openai.api_url', 'https://api.openai.com/v1');
        $this->model = config('services.openai.model', 'gpt-4o');

        if (empty($this->apiKey)) {
            throw new \Exception('OPENAI_API_KEY não configurado no .env');
        }
    }

    /**
     * Retorna o nome do rate limiter para este provider
     */
    protected function getRateLimiterKey(): string
    {
        return 'openai';
    }

    /**
     * Retorna o nome do provider
     */
    public function getName(): string
    {
        return 'OpenAI';
    }

    /**
     * Valida se a API está acessível
     */
    public function healthCheck(): bool
    {
        try {
            $url = "{$this->apiUrl}/models";

            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                ])
                ->get($url);

            return $response->successful();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Faz a chamada HTTP para a API do OpenAI com exponential backoff para rate limiting
     */
    protected function callAPI(string $prompt, bool $deepThinkingEnabled = false): string
    {
        // Aplica rate limiting antes da chamada
        RateLimiterService::apply($this->getRateLimiterKey());

        $url = "{$this->apiUrl}/chat/completions";
        $attempt = 0;
        $lastException = null;

        $requestBody = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Você é um assistente jurídico especializado em análise de processos judiciais. Forneça análises objetivas, estruturadas e fundamentadas em linguagem clara.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.3,
            'max_tokens' => 4096,
        ];

        while ($attempt < static::MAX_RETRIES_ON_RATE_LIMIT) {
            $attempt++;

            try {
                $response = Http::timeout(180)
                    ->withHeaders([
                        'Authorization' => "Bearer {$this->apiKey}",
                        'Content-Type' => 'application/json',
                    ])
                    ->post($url, $requestBody);

                // Se recebeu 429 (rate limit), aplica exponential backoff
                if ($response->status() === 429) {
                    if ($attempt < static::MAX_RETRIES_ON_RATE_LIMIT) {
                        $backoffMs = $this->calculateBackoff($attempt);

                        Log::warning("Rate limit atingido (429). Tentativa {$attempt}/" . static::MAX_RETRIES_ON_RATE_LIMIT . ". Aguardando {$backoffMs}ms", [
                            'attempt' => $attempt,
                            'backoff_ms' => $backoffMs
                        ]);

                        usleep($backoffMs * 1000);
                        continue;
                    }

                    throw new \Exception('Rate limit da API OpenAI excedido após ' . static::MAX_RETRIES_ON_RATE_LIMIT . ' tentativas. Aguarde alguns minutos e tente novamente.');
                }

                if (!$response->successful()) {
                    $statusCode = $response->status();
                    $errorBody = $response->json();
                    $errorMessage = $errorBody['error']['message'] ?? $errorBody['message'] ?? 'Erro desconhecido';

                    throw new \Exception($this->translateError($statusCode, $errorMessage));
                }

                $data = $response->json();

                // Log detalhado da resposta com informações de uso
                $usage = $data['usage'] ?? [];
                Log::info('OpenAI API - Resposta recebida', [
                    'model' => $data['model'] ?? $this->model,
                    'finish_reason' => $data['choices'][0]['finish_reason'] ?? 'unknown',
                    'usage' => [
                        'prompt_tokens' => $usage['prompt_tokens'] ?? 'N/A',
                        'completion_tokens' => $usage['completion_tokens'] ?? 'N/A',
                        'total_tokens' => $usage['total_tokens'] ?? 'N/A',
                    ],
                    'response_id' => $data['id'] ?? 'N/A',
                    'created_at' => isset($data['created']) ? date('Y-m-d H:i:s', $data['created']) : 'N/A',
                ]);

                // Acumula metadados da resposta
                $this->accumulateMetadata($usage, $data['model'] ?? $this->model);

                // Extrai o texto da resposta
                $text = $data['choices'][0]['message']['content'] ?? null;

                if (empty($text)) {
                    Log::error('OpenAI retornou resposta vazia', [
                        'response_data' => $data,
                        'status_code' => $response->status(),
                        'model' => $data['model'] ?? $this->model,
                    ]);
                    throw new \Exception('A API retornou uma resposta vazia. Tente novamente em alguns instantes.');
                }

                return $text;

            } catch (\Exception $e) {
                $lastException = $e;

                // Se for erro de conexão ou timeout, tenta novamente
                if ($attempt < 3 && (
                    str_contains($e->getMessage(), 'timeout') ||
                    str_contains($e->getMessage(), 'Connection') ||
                    str_contains($e->getMessage(), 'cURL error')
                )) {
                    $retryDelay = 2000 * $attempt;
                    Log::warning("Erro de conexão. Tentativa {$attempt}/3. Aguardando {$retryDelay}ms", [
                        'error' => $e->getMessage()
                    ]);
                    usleep($retryDelay * 1000);
                    continue;
                }

                throw $e;
            }
        }

        throw $lastException ?? new \Exception('Falha ao chamar API OpenAI após múltiplas tentativas');
    }

    /**
     * Traduz erros técnicos da API OpenAI para mensagens amigáveis
     */
    protected function translateError(int $statusCode, string $technicalMessage): string
    {
        // Erros de quota/rate limit
        if ($statusCode === 429) {
            if (str_contains(strtolower($technicalMessage), 'quota')) {
                return 'Limite de uso da API OpenAI excedido. Verifique seu plano ou aguarde o reset mensal.';
            }
            return 'Muitas requisições simultâneas. Aguarde alguns segundos e tente novamente.';
        }

        // Erros de autenticação
        if ($statusCode === 401) {
            return 'Chave de API OpenAI inválida. Verifique OPENAI_API_KEY no .env';
        }

        // Erro de conteúdo muito grande
        if ($statusCode === 400 && str_contains(strtolower($technicalMessage), 'maximum context length')) {
            return 'Documento muito grande para o modelo OpenAI. O sistema tentará sumarizar automaticamente.';
        }

        // Erro genérico do servidor
        if ($statusCode >= 500) {
            return "Erro temporário no servidor OpenAI (código {$statusCode}). Tente novamente em alguns minutos.";
        }

        return "Erro na API OpenAI: " . substr($technicalMessage, 0, 150);
    }
}
