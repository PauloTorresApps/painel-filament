<?php

namespace App\Pipeline\Graph\Checkpointers;

use App\Models\DocumentAnalysis;
use App\Pipeline\Graph\Contracts\Checkpointer;
use App\Pipeline\Graph\GraphState;
use InvalidArgumentException;

final class DocumentAnalysisCheckpointer implements Checkpointer
{
    public function save(string $runId, string $nodeId, GraphState $state): void
    {
        $analysisId = (int) $state->get('analysis_id', 0);

        if ($analysisId <= 0) {
            throw new InvalidArgumentException('GraphState must contain a valid analysis_id to persist checkpoints.');
        }

        $analysis = DocumentAnalysis::find($analysisId);

        if (!$analysis) {
            throw new InvalidArgumentException("DocumentAnalysis [{$analysisId}] not found for checkpoint save.");
        }

        $analysis->update([
            'graph_run_id' => $runId,
            'graph_last_node' => $nodeId,
            'graph_state' => $state->all(),
        ]);
    }

    public function load(string $runId): ?GraphState
    {
        $analysis = DocumentAnalysis::where('graph_run_id', $runId)->first();

        if (!$analysis || !is_array($analysis->graph_state)) {
            return null;
        }

        return GraphState::fromArray($analysis->graph_state);
    }

    public function lastNode(string $runId): ?string
    {
        return DocumentAnalysis::where('graph_run_id', $runId)
            ->value('graph_last_node');
    }
}
