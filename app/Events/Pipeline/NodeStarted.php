<?php

namespace App\Events\Pipeline;

final class NodeStarted
{
    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $nodeId,
        public readonly array $state,
    ) {
    }
}
