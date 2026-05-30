<?php

namespace App\Pipeline\Graph\Nodes;

use App\Jobs\ProcessAnalysis\BuildStructuredOpinionJob;
use App\Pipeline\Graph\Contracts\Node;
use App\Pipeline\Graph\GraphState;
use App\Pipeline\Graph\NodeResult;

final class StructuredOpinionNode implements Node
{
    public function id(): string
    {
        return 'structured_opinion';
    }

    public function run(GraphState $state): NodeResult
    {
        BuildStructuredOpinionJob::dispatch((int) $state->get('analysis_id'))->onQueue('analysis');

        return NodeResult::dispatched('designer_brief');
    }
}
