<?php

namespace App\Pipeline\Graph\Checkpointers;

use App\Models\DocumentAnalysis;
use App\Models\PipelineRun;
use App\Pipeline\Graph\Contracts\Checkpointer;
use App\Pipeline\Graph\GraphState;
use InvalidArgumentException;

final class PipelineRunCheckpointer implements Checkpointer
{
    public function save(string $runId, string $nodeId, GraphState $state): void
    {
        $analysisId = (int) $state->get('analysis_id', 0);

        if ($analysisId <= 0) {
            throw new InvalidArgumentException('GraphState must contain analysis_id for PipelineRun checkpointing.');
        }

        PipelineRun::query()->updateOrCreate(
            ['id' => $runId],
            [
                'graph_name' => (string) $state->get('graph_name', 'process_analysis'),
                'entity_type' => 'document_analysis',
                'entity_id' => $analysisId,
                'status' => (string) $state->get('graph_status', 'running'),
                'current_node' => $nodeId,
                'state' => $state->all(),
            ]
        );

        // Mantem sincronia com colunas de checkpoint em document_analyses.
        DocumentAnalysis::where('id', $analysisId)->update([
            'graph_run_id' => $runId,
            'graph_last_node' => $nodeId,
            'graph_state' => $state->all(),
        ]);
    }

    public function load(string $runId): ?GraphState
    {
        $run = PipelineRun::find($runId);

        if (!$run || !is_array($run->state)) {
            return null;
        }

        return GraphState::fromArray($run->state);
    }

    public function lastNode(string $runId): ?string
    {
        return PipelineRun::where('id', $runId)->value('current_node');
    }
}
