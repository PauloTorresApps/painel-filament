<?php

namespace App\Events\Pipeline;

final class NodeFailed
{
    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $nodeId,
        public readonly string $error,
        public readonly array $state,
    ) {
    }
}
