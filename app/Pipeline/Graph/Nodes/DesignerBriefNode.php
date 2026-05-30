<?php

namespace App\Pipeline\Graph\Nodes;

use App\Jobs\ProcessAnalysis\BuildDesignerBriefJob;
use App\Pipeline\Graph\Contracts\Node;
use App\Pipeline\Graph\GraphState;
use App\Pipeline\Graph\NodeResult;

final class DesignerBriefNode implements Node
{
    public function id(): string
    {
        return 'designer_brief';
    }

    public function run(GraphState $state): NodeResult
    {
        BuildDesignerBriefJob::dispatch((int) $state->get('analysis_id'))->onQueue('analysis');

        return NodeResult::halt();
    }
}
