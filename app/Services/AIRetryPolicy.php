<?php

namespace App\Services;

class AIRetryPolicy
{
    /**
     * @var callable
     */
    private $sleepHandler;

    public function __construct(
        private readonly int $rateLimitBackoffBaseMs = 5000,
        private readonly int $connectionRetryBaseDelayMs = 2000,
        ?callable $sleepHandler = null
    ) {
        $this->sleepHandler = $sleepHandler ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    public function execute(callable $apiCall, string $providerName, int $maxRetries): string
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            $attempt++;

            try {
                return $apiCall($attempt);
            } catch (\Exception $e) {
                $lastException = $e;

                if ($this->isRateLimitError($e)) {
                    if ($attempt < $maxRetries) {
                        $backoffMs = $this->calculateBackoff($attempt);
                        $this->logWarning("Rate limit atingido no {$providerName}. Tentativa {$attempt}/{$maxRetries}. Aguardando {$backoffMs}ms");
                        $this->sleep($backoffMs);
                        continue;
                    }
                }

                if ($attempt < 3 && $this->isConnectionError($e)) {
                    $retryDelay = $this->connectionRetryBaseDelayMs * $attempt;
                    $this->logWarning("Erro de conexão no {$providerName}. Tentativa {$attempt}/3. Aguardando {$retryDelay}ms");
                    $this->sleep($retryDelay);
                    continue;
                }

                throw $e;
            }
        }

        throw $lastException ?? new \Exception("Falha ao chamar API {$providerName} após múltiplas tentativas");
    }

    public function isRateLimitError(\Exception $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, '429')
            || str_contains($message, 'rate limit')
            || str_contains($message, 'too many requests');
    }

    public function isConnectionError(\Exception $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'timeout')
            || str_contains($message, 'connection')
            || str_contains($message, 'curl error')
            || str_contains($message, '504');
    }

    public function calculateBackoff(int $attempt): int
    {
        $baseBackoff = $this->rateLimitBackoffBaseMs * (int) pow(2, $attempt - 1);
        $jitterMs = random_int(0, 1000);

        return $baseBackoff + $jitterMs;
    }

    private function sleep(int $milliseconds): void
    {
        ($this->sleepHandler)($milliseconds);
    }

    private function logWarning(string $message): void
    {
        try {
            \Illuminate\Support\Facades\Log::warning($message);
        } catch (\Throwable) {
            // Unit tests isolados podem não ter facades inicializadas.
        }
    }
}