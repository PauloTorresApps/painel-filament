<?php

namespace App\Pipeline\Graph\Conditions;

use App\Pipeline\Graph\GraphState;

final class DirectConsolidationFitsCondition
{
    public function evaluate(int $startFromIndex, int $totalChars, int $directConsolidationLimit): bool
    {
        return $startFromIndex === 0 && $totalChars <= $directConsolidationLimit;
    }

    public function __invoke(GraphState $state): bool
    {
        return $this->evaluate(
            startFromIndex: (int) $state->get('start_from_index', 0),
            totalChars: (int) $state->get('total_chars', 0),
            directConsolidationLimit: (int) $state->get('direct_consolidation_limit', 0)
        );
    }
}
