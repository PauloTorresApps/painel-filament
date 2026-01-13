<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class RateLimiterService
{
    /**
     * Aguarda se necessário para respeitar o rate limit
     *
     * @param string $provider Nome do provider (gemini, deepseek, openai)
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

        try {
            // Tenta obter o timestamp da última requisição
            $lastRequestTime = Redis::get($key);

            if ($lastRequestTime !== null) {
                $timeSinceLastRequest = (microtime(true) * 1000) - (float) $lastRequestTime;

                // Se passou menos tempo que o necessário, aguarda
                if ($timeSinceLastRequest < $minDelayMs) {
                    $sleepMs = (int) ($minDelayMs - $timeSinceLastRequest);

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
            // Se o Redis falhar, não bloqueia a execução
            Log::warning('Erro ao aplicar rate limiting', [
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Retorna o rate limit configurado para um provider
     *
     * @param string $provider Nome do provider (gemini, deepseek, openai)
     * @return int Limite de requisições por minuto
     */
    public static function getRateLimit(string $provider): int
    {
        return match (strtolower($provider)) {
            'gemini' => (int) config('services.gemini.rate_limit_per_minute', 15),
            'deepseek' => (int) config('services.deepseek.rate_limit_per_minute', 60),
            'openai' => (int) config('services.openai.rate_limit_per_minute', 3),
            default => 10, // Valor padrão conservador
        };
    }

    /**
     * Aplica rate limiting baseado no provider
     *
     * @param string $provider Nome do provider (gemini, deepseek, openai)
     * @return void
     */
    public static function apply(string $provider): void
    {
        $rateLimit = self::getRateLimit($provider);
        self::throttle($provider, $rateLimit);
    }

    /**
     * Limpa o rate limit de um provider (útil para testes)
     *
     * @param string $provider Nome do provider
     * @return void
     */
    public static function clear(string $provider): void
    {
        try {
            Redis::del("rate_limit:{$provider}");
        } catch (\Exception $e) {
            Log::warning('Erro ao limpar rate limiting', [
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);
        }
    }
}
