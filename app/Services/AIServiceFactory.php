<?php

namespace App\Services;

use App\Contracts\AIProviderInterface;

/**
 * Factory para criação de serviços de IA
 * 
 * Centraliza a lógica de instanciação de providers de IA,
 * eliminando duplicação em múltiplos Jobs.
 */
class AIServiceFactory
{
    /**
     * Cria uma instância do serviço de IA apropriado
     *
     * @param string $provider Nome do provider (gemini, openai, deepseek)
     * @return AIProviderInterface
     */
    public static function make(string $provider): AIProviderInterface
    {
        return match ($provider) {
            'gemini' => new GeminiService(),
            'openai' => new OpenAIService(),
            'deepseek' => new DeepSeekService(),
            default => new GeminiService(),
        };
    }

    /**
     * Cria uma instância do serviço de IA com modelo específico
     *
     * @param string $provider Nome do provider
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
