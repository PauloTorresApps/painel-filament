<?php

namespace App\Pipeline\Graph\Nodes;

use App\Jobs\ProcessAnalysis\RunProcessEngineJob;
use App\Pipeline\Graph\Contracts\Node;
use App\Pipeline\Graph\GraphState;
use App\Pipeline\Graph\NodeResult;

final class EngineNode implements Node
{
    public function id(): string
    {
        return 'engine';
    }

    public function run(GraphState $state): NodeResult
    {
        RunProcessEngineJob::dispatch((int) $state->get('analysis_id'))->onQueue('analysis');

        return NodeResult::dispatched('structured_opinion');
    }
}
