<?php

namespace App\Strategies;

use App\Contracts\AIProviderInterface;
use App\Models\DocumentMicroAnalysis;
use Illuminate\Support\Facades\Log;

class PdfNativeProcessingStrategy implements DocumentProcessingStrategy
{
    public function canHandle(DocumentMicroAnalysis $microAnalysis): bool
    {
        $strategy = $microAnalysis->processing_strategy ?? 'text';

        return in_array($strategy, ['pdf_text', 'pdf_ocr'], true)
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
            throw new \RuntimeException("Falha ao obter conteúdo base64 para PDF nativo (micro_id: {$microAnalysis->id})");
        }

        $strategy = $microAnalysis->processing_strategy;
        $fullPrompt = $systemPrompt . "\n\n" . $documentPrompt;

        Log::info('PdfNativeProcessingStrategy: Enviando PDF nativo', [
            'micro_id' => $microAnalysis->id,
            'engine' => $strategy === 'pdf_ocr' ? 'mistral-ocr' : 'pdf-text',
        ]);

        return $aiService->analyzePdfDocument(
            $fullPrompt,
            $base64,
            $microAnalysis->descricao . '.pdf',
            $microAnalysis->is_scanned ?? false,
            $deepThinkingEnabled,
            $systemPrompt
        );
    }
}
