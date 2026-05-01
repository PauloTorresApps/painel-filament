<?php

namespace App\Services;

use App\Contracts\AIProviderInterface;

/**
 * Factory para criação de serviços de IA
 *
 * Centraliza a lógica de instanciação do provider OpenRouter.
 */
class AIServiceFactory
{
    /**
     * Cria uma instância do serviço de IA (OpenRouter)
     *
     * @param string $provider Nome do provider (mantido para compatibilidade)
     * @return AIProviderInterface
     */
    public static function make(string $provider = 'openrouter'): AIProviderInterface
    {
        return new OpenRouterService();
    }

}
