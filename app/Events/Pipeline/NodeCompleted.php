<?php

namespace App\Events\Pipeline;

final class NodeCompleted
{
    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $nodeId,
        public readonly string $status,
        public readonly array $state,
    ) {
    }
}
