<?php

namespace App\Pipeline\Graph\Contracts;

use App\Pipeline\Graph\GraphState;

interface Checkpointer
{
    public function save(string $runId, string $nodeId, GraphState $state): void;

    public function load(string $runId): ?GraphState;

    public function lastNode(string $runId): ?string;
}
