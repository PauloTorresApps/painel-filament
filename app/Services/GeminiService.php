<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService extends AbstractAIService
{
    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->apiUrl = config('services.gemini.api_url');
        $this->model = config('services.gemini.model', 'gemini-1.5-flash');

        if (empty($this->apiKey)) {
            throw new \Exception('GEMINI_API_KEY não configurado no .env');
        }
    }

    /**
     * Retorna o nome do rate limiter para este provider
     */
    protected function getRateLimiterKey(): string
    {
        return 'gemini';
    }

    /**
     * Retorna o nome do provider
     */
    public function getName(): string
    {
        return 'Google Gemini';
    }

    /**
     * Valida se a API está acessível
     */
    public function healthCheck(): bool
    {
        try {
            $response = $this->callAPI('Hello');
            return !empty($response);
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Faz a chamada HTTP para a API do Gemini com exponential backoff para rate limiting
     */
    protected function callAPI(string $prompt, bool $deepThinkingEnabled = false): string
    {
        // Aplica rate limiting antes da chamada
        RateLimiterService::apply($this->getRateLimiterKey());

        $url = "{$this->apiUrl}/{$this->model}:generateContent?key={$this->apiKey}";
        $attempt = 0;
        $lastException = null;

        while ($attempt < static::MAX_RETRIES_ON_RATE_LIMIT) {
            $attempt++;

            try {
                $response = Http::timeout(300) // 5 minutos de timeout para análises jurídicas longas
                    ->post($url, [
                        'contents' => [
                            [
                                'parts' => [
                                    ['text' => $prompt]
                                ]
                            ]
                        ],
                        'generationConfig' => [
                            'temperature' => 0.4, // Mais determinístico para análises jurídicas
                            'topK' => 32,
                            'topP' => 0.95,
                            'maxOutputTokens' => 8192, // Permite respostas longas
                        ],
                        'safetySettings' => [
                            [
                                'category' => 'HARM_CATEGORY_HARASSMENT',
                                'threshold' => 'BLOCK_NONE'
                            ],
                            [
                                'category' => 'HARM_CATEGORY_HATE_SPEECH',
                                'threshold' => 'BLOCK_NONE'
                            ],
                            [
                                'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                                'threshold' => 'BLOCK_NONE'
                            ],
                            [
                                'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                                'threshold' => 'BLOCK_NONE'
                            ]
                        ]
                    ]);

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

                    throw new \Exception('Rate limit da API Gemini excedido após ' . static::MAX_RETRIES_ON_RATE_LIMIT . ' tentativas. Aguarde alguns minutos e tente novamente.');
                }

                if (!$response->successful()) {
                    $statusCode = $response->status();
                    $errorBody = $response->json();
                    $errorMessage = $errorBody['error']['message'] ?? 'Erro desconhecido';

                    throw new \Exception($this->translateError($statusCode, $errorMessage));
                }

                $data = $response->json();

                // Log detalhado da resposta com informações de uso
                $usageMetadata = $data['usageMetadata'] ?? [];
                Log::info('Gemini API - Resposta recebida', [
                    'model' => $this->model,
                    'finish_reason' => $data['candidates'][0]['finishReason'] ?? 'unknown',
                    'usage' => [
                        'prompt_tokens' => $usageMetadata['promptTokenCount'] ?? 'N/A',
                        'completion_tokens' => $usageMetadata['candidatesTokenCount'] ?? 'N/A',
                        'total_tokens' => $usageMetadata['totalTokenCount'] ?? 'N/A',
                    ],
                    'safety_ratings' => $data['candidates'][0]['safetyRatings'] ?? [],
                ]);

                // Acumula metadados da resposta (normaliza formato Gemini para padrão)
                $this->accumulateMetadata([
                    'prompt_tokens' => $usageMetadata['promptTokenCount'] ?? 0,
                    'completion_tokens' => $usageMetadata['candidatesTokenCount'] ?? 0,
                    'total_tokens' => $usageMetadata['totalTokenCount'] ?? 0,
                ], $this->model);

                // Extrai o texto da resposta
                $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

                if (empty($text)) {
                    Log::error('Gemini retornou resposta vazia', [
                        'response_data' => $data,
                        'status_code' => $response->status(),
                        'model' => $this->model,
                    ]);
                    throw new \Exception('A API retornou uma resposta vazia. Tente novamente em alguns instantes.');
                }

                return $text;

            } catch (\Exception $e) {
                $lastException = $e;

                // Se for erro de conexão ou timeout, tenta novamente com backoff menor
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

        throw $lastException ?? new \Exception('Falha ao chamar API Gemini após múltiplas tentativas');
    }

    /**
     * Traduz erros técnicos da API Gemini para mensagens amigáveis
     */
    protected function translateError(int $statusCode, string $technicalMessage): string
    {
        // Erros de quota/rate limit
        if ($statusCode === 429) {
            if (str_contains(strtolower($technicalMessage), 'quota')) {
                return 'Limite de uso da API de IA excedido. Por favor, verifique seu plano no Google AI Studio ou aguarde até amanhã para novas análises.';
            }
            return 'Muitas requisições simultâneas. Por favor, aguarde alguns segundos e tente novamente.';
        }

        // Erros de autenticação
        if ($statusCode === 401 || $statusCode === 403) {
            return 'Chave de API inválida ou sem permissões. Verifique a configuração GEMINI_API_KEY no arquivo .env';
        }

        // Erros de tamanho de conteúdo
        if ($statusCode === 413 || str_contains(strtolower($technicalMessage), 'too large')) {
            return 'O documento é muito grande para ser processado. Tente enviar menos documentos por vez.';
        }

        // Timeout
        if ($statusCode === 504 || str_contains(strtolower($technicalMessage), 'timeout')) {
            return 'A análise demorou muito tempo. Tente novamente com documentos menores.';
        }

        // Erro de conteúdo bloqueado por safety
        if (str_contains(strtolower($technicalMessage), 'safety') || str_contains(strtolower($technicalMessage), 'blocked')) {
            return 'O conteúdo foi bloqueado pelos filtros de segurança da API. Tente com outros documentos.';
        }

        // Erro genérico do servidor
        if ($statusCode >= 500) {
            return "Erro temporário no servidor da Google AI (código {$statusCode}). Tente novamente em alguns minutos.";
        }

        return "Erro na API de IA: " . substr($technicalMessage, 0, 150);
    }

    /**
     * Calcula custo estimado de uma requisição
     */
    public function estimateCost(int $inputTokens, int $outputTokens = 2000): float
    {
        $pricePerMillionInput = $this->model === 'gemini-1.5-pro' ? 3.50 : 0.075;
        $pricePerMillionOutput = $this->model === 'gemini-1.5-pro' ? 10.50 : 0.30;

        $inputCost = ($inputTokens / 1000000) * $pricePerMillionInput;
        $outputCost = ($outputTokens / 1000000) * $pricePerMillionOutput;

        return $inputCost + $outputCost;
    }
}
