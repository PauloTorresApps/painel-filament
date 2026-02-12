<?php

namespace App\Strategies;

use App\Contracts\AIProviderInterface;
use App\Models\DocumentMicroAnalysis;

interface DocumentProcessingStrategy
{
    /**
     * Verifica se esta estratégia pode processar a micro-análise dada.
     */
    public function canHandle(DocumentMicroAnalysis $microAnalysis): bool;

    /**
     * Processa o documento e retorna o texto da análise.
     */
    public function process(
        DocumentMicroAnalysis $microAnalysis,
        AIProviderInterface $aiService,
        string $systemPrompt,
        string $documentPrompt,
        bool $deepThinkingEnabled
    ): string;
}
