<?php

namespace App\Pipeline\Graph\Nodes;

use App\Jobs\ProcessAnalysis\RefineReduceJob;
use App\Pipeline\Graph\Contracts\Node;
use App\Pipeline\Graph\GraphState;
use App\Pipeline\Graph\NodeResult;

final class RefineReduceNode implements Node
{
    public function id(): string
    {
        return 'refine_reduce';
    }

    public function run(GraphState $state): NodeResult
    {
        $job = new RefineReduceJob(
            documentAnalysisId: (int) $state->get('analysis_id'),
            aiProvider: (string) $state->get('ai_provider'),
            deepThinkingEnabled: (bool) $state->get('deep_thinking_enabled', false),
            promptTemplate: (string) $state->get('prompt_template', ''),
            aiModelId: $state->get('ai_model_id'),
            startFromIndex: (int) $state->get('start_from_index', 0)
        );

        $job->setContextoDados((array) $state->get('contexto_dados', []));

        dispatch($job)->onQueue('analysis');

        return NodeResult::dispatched('chronology');
    }
}
