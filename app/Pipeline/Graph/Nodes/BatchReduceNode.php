<?php

namespace App\Pipeline\Graph\Nodes;

use App\Jobs\ProcessAnalysis\ReduceDocumentAnalysisJob;
use App\Pipeline\Graph\Contracts\Node;
use App\Pipeline\Graph\GraphState;
use App\Pipeline\Graph\NodeResult;

final class BatchReduceNode implements Node
{
    public function id(): string
    {
        return 'batch_reduce';
    }

    public function run(GraphState $state): NodeResult
    {
        ReduceDocumentAnalysisJob::dispatch(
            documentAnalysisId: (int) $state->get('analysis_id'),
            aiProvider: (string) $state->get('ai_provider'),
            deepThinkingEnabled: (bool) $state->get('deep_thinking_enabled', false),
            promptTemplate: (string) $state->get('prompt_template', ''),
            aiModelId: $state->get('ai_model_id'),
            currentReduceLevel: (int) $state->get('current_reduce_level', 1)
        )->onQueue('analysis');

        return NodeResult::dispatched('chronology');
    }
}
