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
        // Aplica rate limiting antes da chamada
        RateLimiterService::apply($this->getRateLimiterKey());

        $url = "{$this->apiUrl}/chat/completions";
        $attempt = 0;
        $lastException = null;

        // Determina o modelo correto baseado no deep thinking
        // - Se deep thinking está HABILITADO: usa deepseek-reasoner com thinking mode
        // - Se deep thinking está DESABILITADO: usa deepseek-chat (rápido)
        $modelToUse = $this->model;
        $useThinkingMode = false;

        if ($deepThinkingEnabled) {
            // Deep thinking habilitado: usa modelo reasoner
            $modelToUse = 'deepseek-reasoner';
            $useThinkingMode = true;
            Log::info('Deep Thinking habilitado - usando DeepSeek Reasoner com thinking mode', [
                'original_model' => $this->model,
                'model_used' => $modelToUse,
                'timeout' => '600s'
            ]);
        } elseif (str_contains($this->model, 'reasoner')) {
            // Modelo é reasoner mas deep thinking está desabilitado: usa chat
            $modelToUse = 'deepseek-chat';
            Log::info('Modelo deepseek-reasoner configurado mas deep thinking desabilitado. Usando deepseek-chat.', [
                'original_model' => $this->model,
                'fallback_model' => $modelToUse
            ]);
        }

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
            'temperature' => 0.4,
            'max_tokens' => 4096,
            'stream' => false,
        ];

        // Adiciona o parâmetro thinking se deep thinking estiver ativado
        if ($useThinkingMode) {
            $requestBody['thinking'] = ['type' => 'enabled'];
        }

        while ($attempt < static::MAX_RETRIES_ON_RATE_LIMIT) {
            $attempt++;

            try {
                // Timeout maior para modo de pensamento profundo (reasoning)
                $timeout = $deepThinkingEnabled ? 600 : 300;

                $response = Http::timeout($timeout)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . $this->apiKey,
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

                    throw new \Exception('Rate limit da API DeepSeek excedido após ' . static::MAX_RETRIES_ON_RATE_LIMIT . ' tentativas. Aguarde alguns minutos e tente novamente.');
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

                // Extrai o texto da resposta (formato OpenAI-compatible)
                $text = $data['choices'][0]['message']['content'] ?? null;

                if (empty($text)) {
                    Log::error('DeepSeek retornou resposta vazia', [
                        'response_data' => $data,
                        'status_code' => $response->status(),
                        'model' => $data['model'] ?? $modelToUse,
                    ]);
                    throw new \Exception('A API retornou uma resposta vazia. Tente novamente em alguns instantes.');
                }

                return $text;

            } catch (\Exception $e) {
                $lastException = $e;

                // Se for erro de conexão ou timeout, tenta novamente
                $maxRetries = str_contains($e->getMessage(), 'timeout') ? 3 : 2;

                if ($attempt < $maxRetries && (
                    str_contains($e->getMessage(), 'timeout') ||
                    str_contains($e->getMessage(), 'Connection') ||
                    str_contains($e->getMessage(), 'cURL error')
                )) {
                    $retryDelay = 3000 * $attempt;

                    if (str_contains($e->getMessage(), 'timeout')) {
                        Log::warning("Timeout na API DeepSeek (reasoning mode pode ser lento). Tentativa {$attempt}/{$maxRetries}. Aguardando {$retryDelay}ms", [
                            'error' => $e->getMessage(),
                            'deep_thinking' => $deepThinkingEnabled
                        ]);
                    } else {
                        Log::warning("Erro de conexão. Tentativa {$attempt}/{$maxRetries}. Aguardando {$retryDelay}ms", [
                            'error' => $e->getMessage()
                        ]);
                    }

                    usleep($retryDelay * 1000);
                    continue;
                }

                throw $e;
            }
        }

        throw $lastException ?? new \Exception('Falha ao chamar API DeepSeek após múltiplas tentativas');
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
