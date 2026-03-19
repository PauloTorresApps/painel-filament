<?php

namespace App\Services;

use App\Strategies\PdfNativeProcessingStrategy;
use App\Strategies\TextProcessingStrategy;
use App\Strategies\VisionProcessingStrategy;

class DocumentProcessingStrategyFactory
{
    /**
     * Cria a cadeia de strategies para a fase MAP.
     *
     * @param array<string, mixed> $schema
     * @return array<int, object>
     */
    public function makeMapStrategies(array $schema): array
    {
        return [
            app(VisionProcessingStrategy::class),
            app(PdfNativeProcessingStrategy::class),
            app()->makeWith(TextProcessingStrategy::class, ['mapAnalysisSchema' => $schema]),
        ];
    }
}
