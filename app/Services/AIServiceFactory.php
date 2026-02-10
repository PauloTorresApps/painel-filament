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

    /**
     * Cria uma instância do serviço de IA com modelo específico
     *
     * @param string $provider Nome do provider (mantido para compatibilidade)
     * @param string|null $modelId ID do modelo específico
     * @return AIProviderInterface
     */
    public static function makeWithModel(string $provider, ?string $modelId = null): AIProviderInterface
    {
        $service = self::make($provider);

        if ($modelId) {
            $service->setModel($modelId);
        }

        return $service;
    }
}
