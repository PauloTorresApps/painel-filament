<?php

namespace App\Pipeline\Graph\Conditions;

use App\Pipeline\Graph\GraphState;

final class NeedsMoreReduceLevelsCondition
{
    public function evaluate(int $completedCount, int $completedLevel, ?int $batchSize = null, ?int $maxLevels = null): bool
    {
        $safeBatchSize = $batchSize ?? (int) config('analysis.reduce.batch_size', 10);
        $safeMaxLevels = $maxLevels ?? (int) config('analysis.reduce.max_levels', 5);

        return $completedCount > $safeBatchSize && $completedLevel < $safeMaxLevels;
    }

    public function __invoke(GraphState $state): bool
    {
        return $this->evaluate(
            completedCount: (int) $state->get('completed_count', 0),
            completedLevel: (int) $state->get('completed_level', 1),
            batchSize: $state->get('reduce_batch_size'),
            maxLevels: $state->get('reduce_max_levels')
        );
    }
}
