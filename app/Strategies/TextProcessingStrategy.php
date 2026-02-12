<?php

namespace App\Strategies;

use App\Contracts\AIProviderInterface;
use App\Models\DocumentMicroAnalysis;
use Illuminate\Support\Facades\Log;

class TextProcessingStrategy implements DocumentProcessingStrategy
{
    public function __construct(
        private readonly ?array $mapAnalysisSchema = null
    ) {}

    public function canHandle(DocumentMicroAnalysis $microAnalysis): bool
    {
        return mb_strlen($microAnalysis->extracted_text ?? '') > 0;
    }

    public function process(
        DocumentMicroAnalysis $microAnalysis,
        AIProviderInterface $aiService,
        string $systemPrompt,
        string $documentPrompt,
        bool $deepThinkingEnabled
    ): string {
        $textLength = mb_strlen($microAnalysis->extracted_text ?? '');

        Log::info('TextProcessingStrategy: Usando análise por texto extraído', [
            'micro_id' => $microAnalysis->id,
            'text_length' => $textLength,
            'structured_output' => config('services.openrouter.structured_map_enabled', false),
        ]);

        // Se structured outputs habilitado, usa JSON schema para resposta consistente
        if (config('services.openrouter.structured_map_enabled', false) && $this->mapAnalysisSchema) {
            try {
                return $aiService->analyzeSingleDocumentStructured(
                    $documentPrompt,
                    $microAnalysis->extracted_text ?? '',
                    $this->mapAnalysisSchema,
                    $deepThinkingEnabled,
                    $systemPrompt
                );
            } catch (\Exception $e) {
                Log::warning('TextProcessingStrategy: Structured output falhou, usando texto livre', [
                    'micro_id' => $microAnalysis->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $aiService->analyzeSingleDocument(
            $documentPrompt,
            $microAnalysis->extracted_text ?? '',
            $deepThinkingEnabled,
            $systemPrompt
        );
    }
}
