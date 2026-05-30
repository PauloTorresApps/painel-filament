<?php

namespace App\Events\Pipeline;

final class GraphCompleted
{
    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $finalStatus,
        public readonly array $state,
    ) {
    }
}
