<?php

namespace App\Pipeline\Graph\Nodes;

use App\Jobs\ProcessAnalysis\BuildInventoryJob;
use App\Pipeline\Graph\Contracts\Node;
use App\Pipeline\Graph\GraphState;
use App\Pipeline\Graph\NodeResult;

final class InventoryNode implements Node
{
    public function id(): string
    {
        return 'inventory';
    }

    public function run(GraphState $state): NodeResult
    {
        BuildInventoryJob::dispatch(
            analysisId: (int) $state->get('analysis_id'),
            aiProvider: (string) $state->get('ai_provider'),
            deepThinkingEnabled: (bool) $state->get('deep_thinking_enabled', false),
            contextoDados: (array) $state->get('contexto_dados', []),
            aiModelId: $state->get('ai_model_id'),
            userId: (int) $state->get('user_id'),
            reduceStrategy: (string) $state->get('reduce_strategy', 'auto'),
            mapModelId: $state->get('map_model_id')
        )->onQueue('analysis');

        return NodeResult::dispatched('dispatch_map_phase');
    }
}
