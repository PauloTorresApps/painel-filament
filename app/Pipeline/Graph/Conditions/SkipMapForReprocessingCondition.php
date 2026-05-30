<?php

namespace App\Pipeline\Graph\Conditions;

use App\Pipeline\Graph\GraphState;

final class SkipMapForReprocessingCondition
{
    public function evaluate(int $pendingCount, int $completedCount): bool
    {
        return $pendingCount === 0 && $completedCount > 0;
    }

    public function __invoke(GraphState $state): bool
    {
        return $this->evaluate(
            pendingCount: (int) $state->get('pending_count', 0),
            completedCount: (int) $state->get('completed_count', 0)
        );
    }
}
