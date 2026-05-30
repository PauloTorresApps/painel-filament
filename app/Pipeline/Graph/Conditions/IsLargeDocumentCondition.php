<?php

namespace App\Pipeline\Graph\Conditions;

use App\Pipeline\Graph\GraphState;

final class IsLargeDocumentCondition
{
    public function evaluate(int $textLength, ?int $threshold = null): bool
    {
        $limit = $threshold ?? (int) config('analysis.thresholds.large_document_chars', 100000);

        return $textLength > $limit;
    }

    public function __invoke(GraphState $state): bool
    {
        return $this->evaluate(
            textLength: (int) $state->get('text_length', 0),
            threshold: $state->get('large_document_threshold')
        );
    }
}
