<?php

namespace App\Strategies\ProcessAnalysis;

use App\Contracts\AIProviderInterface;
use App\Models\DocumentMicroAnalysis;
use Illuminate\Support\Facades\Log;

class VisionProcessingStrategy implements DocumentProcessingStrategy
{
    public function canHandle(DocumentMicroAnalysis $microAnalysis): bool
    {
        return ($microAnalysis->processing_strategy ?? 'text') === 'vision'
            && $microAnalysis->hasOriginalContent();
    }

    public function process(
        DocumentMicroAnalysis $microAnalysis,
        AIProviderInterface $aiService,
        string $systemPrompt,
        string $documentPrompt,
        bool $deepThinkingEnabled
    ): string {
        $base64 = $microAnalysis->getOriginalContentBase64();

        if (!$base64) {
            throw new \RuntimeException("Falha ao obter conteúdo base64 para visão (micro_id: {$microAnalysis->id})");
        }

        $fullPrompt = $systemPrompt . "\n\n" . $documentPrompt;

        Log::info('VisionProcessingStrategy: Enviando imagem via visão direta', [
            'micro_id' => $microAnalysis->id,
            'mimetype' => $microAnalysis->mimetype,
        ]);

        return $aiService->analyzeImageDocument(
            $fullPrompt,
            $base64,
            $microAnalysis->mimetype,
            $deepThinkingEnabled,
            $systemPrompt
        );
    }
}
