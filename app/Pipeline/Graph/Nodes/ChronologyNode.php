<?php

namespace App\Pipeline\Graph\Nodes;

use App\Jobs\ProcessAnalysis\BuildChronologyJob;
use App\Pipeline\Graph\Contracts\Node;
use App\Pipeline\Graph\GraphState;
use App\Pipeline\Graph\NodeResult;

final class ChronologyNode implements Node
{
    public function id(): string
    {
        return 'chronology';
    }

    public function run(GraphState $state): NodeResult
    {
        BuildChronologyJob::dispatch((int) $state->get('analysis_id'))->onQueue('analysis');

        return NodeResult::dispatched('engine');
    }
}
