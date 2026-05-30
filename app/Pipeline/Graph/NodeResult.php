<?php

namespace App\Pipeline\Graph;

final class NodeResult
{
    /**
     * @param array<string, mixed> $stateUpdates
     */
    private function __construct(
        public readonly string $status,
        public readonly ?string $nextNodeId = null,
        public readonly array $stateUpdates = [],
    ) {
    }

    /**
     * @param array<string, mixed> $stateUpdates
     */
    public static function dispatched(?string $nextNodeId = null, array $stateUpdates = []): self
    {
        return new self('dispatched', $nextNodeId, $stateUpdates);
    }

    /**
     * @param array<string, mixed> $stateUpdates
     */
    public static function continue(?string $nextNodeId = null, array $stateUpdates = []): self
    {
        return new self('continue', $nextNodeId, $stateUpdates);
    }

    /**
     * @param array<string, mixed> $stateUpdates
     */
    public static function halt(array $stateUpdates = []): self
    {
        return new self('halt', null, $stateUpdates);
    }

    /**
     * @param array<string, mixed> $stateUpdates
     */
    public static function fail(array $stateUpdates = []): self
    {
        return new self('fail', null, $stateUpdates);
    }
}
