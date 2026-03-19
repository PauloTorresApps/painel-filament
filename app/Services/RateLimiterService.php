<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class RateLimiterService
{
    /**
     * Aguarda se necessário para respeitar o rate limit
     *
     * @param string $provider Nome do provider (openrouter)
     * @param int $rateLimit Limite de requisições por minuto
     * @return void
     */
    public static function throttle(string $provider, int $rateLimit): void
    {
        if ($rateLimit <= 0) {
            return; // Sem limitação
        }

        $key = "rate_limit:{$provider}";
        $windowSeconds = 60; // Janela de 1 minuto
        $minDelayMs = (int) (($windowSeconds * 1000) / $rateLimit); // Delay mínimo entre requisições em ms
        $maxBlockingMs = (int) config('analysis.ai.max_blocking_retry_ms', 2000);

        try {
            // Tenta obter o timestamp da última requisição
            $lastRequestTime = Redis::get($key);

            if ($lastRequestTime !== null) {
                $timeSinceLastRequest = (microtime(true) * 1000) - (float) $lastRequestTime;

                // Se passou menos tempo que o necessário, aguarda
                if ($timeSinceLastRequest < $minDelayMs) {
                    $sleepMs = min((int) ($minDelayMs - $timeSinceLastRequest), $maxBlockingMs);

                    Log::info("Rate limiting: aguardando {$sleepMs}ms", [
                        'provider' => $provider,
                        'rate_limit' => $rateLimit,
                        'min_delay_ms' => $minDelayMs
                    ]);

                    usleep($sleepMs * 1000); // Converte ms para microsegundos
                }
            }

            // Atualiza o timestamp da última requisição
            Redis::setex($key, $windowSeconds, (string) (microtime(true) * 1000));

        } catch (\Exception $e) {
            // Se o Redis falhar, aplica fallback com delay fixo para evitar flood na API
            Log::warning('Rate limiting: Redis indisponível, aplicando fallback', [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'fallback_delay_ms' => $minDelayMs
            ]);

            // Fallback: aplica delay mínimo entre requisições mesmo sem Redis
            usleep(min($minDelayMs, $maxBlockingMs) * 1000);
        }
    }

    /**
     * Retorna o rate limit configurado para um provider
     *
     * @param string $provider Nome do provider (openrouter)
     * @return int Limite de requisições por minuto
     */
    public static function getRateLimit(string $provider): int
    {
        return (int) config('services.openrouter.rate_limit_per_minute', 30);
    }

    /**
     * Aplica rate limiting baseado no provider
     *
     * @param string $provider Nome do provider (openrouter)
     * @return void
     */
    public static function apply(string $provider): void
    {
        $rateLimit = self::getRateLimit($provider);
        self::throttle($provider, $rateLimit);
    }

}
