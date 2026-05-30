<?php

namespace App\Pipeline\Graph\Conditions;

use App\Pipeline\Graph\GraphState;

final class UseRefineStrategyCondition
{
    public function evaluate(int $docCount, ?int $totalChars, string $reduceStrategy = 'auto'): bool
    {
        if ($reduceStrategy === 'refine') {
            return true;
        }

        if ($reduceStrategy === 'batch') {
            return false;
        }

        if ($docCount > config('analysis.thresholds.refine_max_documents', 20)) {
            return false;
        }

        $directConsolidationLimit = (int) config('analysis.reduce.direct_consolidation_chars', 800000);

        if (!is_null($totalChars) && $totalChars > $directConsolidationLimit) {
            return false;
        }

        return true;
    }

    public function __invoke(GraphState $state): bool
    {
        return $this->evaluate(
            docCount: (int) $state->get('doc_count', 0),
            totalChars: $state->get('total_chars'),
            reduceStrategy: (string) $state->get('reduce_strategy', 'auto')
        );
    }
}
