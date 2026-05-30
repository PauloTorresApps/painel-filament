<?php

namespace App\Pipeline\Graph;

use App\Events\Pipeline\GraphCompleted;
use App\Events\Pipeline\NodeCompleted;
use App\Events\Pipeline\NodeFailed;
use App\Events\Pipeline\NodeStarted;
use App\Pipeline\Graph\Contracts\Checkpointer;
use App\Pipeline\Graph\Contracts\Node;
use App\Services\OtelMetricsService;
use App\Traits\WithOtelTracing;
use Illuminate\Support\Str;

final class GraphRunner
{
    use WithOtelTracing;

    public function run(Node $node, GraphState $state): NodeResult
    {
        return $node->run($state);
    }

    public function runGraph(
        Graph $graph,
        GraphState $initialState,
        ?Checkpointer $checkpointer = null,
        ?string $startNodeId = null
    ): NodeResult {
        $compiled = $graph->compile();

        $runId = (string) ($initialState->get('graph_run_id') ?: Str::uuid());
        $state = $initialState->with('graph_run_id', $runId);
        $currentNodeId = $startNodeId ?? $compiled->entryNodeId();
        $lastResult = NodeResult::halt();
        $previousNodeId = null;

        while ($currentNodeId !== null) {
            $nodeStartNs = hrtime(true);
            $this->dispatchEventSafely(new NodeStarted($runId, $currentNodeId, $state->all()));

            [$span, $scope] = $this->startSpan('painel-laravel-graph', "graph.node.{$currentNodeId}", [
                'graph.run_id' => $runId,
                'graph.node_id' => $currentNodeId,
                'graph.previous_node' => $previousNodeId,
                'analysis.id' => $state->get('analysis_id'),
            ]);

            try {
                $node = $compiled->nodeById($currentNodeId);
                $lastResult = $this->run($node, $state);
                $this->finishSpanSuccess($span);

                $this->recordNodeMetric($state, $currentNodeId, $lastResult->status, $nodeStartNs);
                $this->dispatchEventSafely(
                    new NodeCompleted($runId, $currentNodeId, $lastResult->status, $state->all())
                );
            } catch (\Throwable $exception) {
                $this->finishSpanError($span, $exception);
                $this->recordNodeMetric($state, $currentNodeId, 'failed', $nodeStartNs);
                $this->dispatchEventSafely(
                    new NodeFailed($runId, $currentNodeId, $exception->getMessage(), $state->all())
                );

                if ($checkpointer) {
                    $checkpointer->save(
                        $runId,
                        $currentNodeId,
                        $state->merge(['graph_status' => 'failed'])
                    );
                }

                throw $exception;
            } finally {
                $this->detachScope($scope);
            }

            if (!empty($lastResult->stateUpdates)) {
                $state = $state->merge($lastResult->stateUpdates);
            }

            if ($checkpointer) {
                $checkpointer->save($runId, $currentNodeId, $state);
            }

            if (in_array($lastResult->status, ['fail', 'halt', 'dispatched'], true)) {
                $this->dispatchEventSafely(new GraphCompleted($runId, $lastResult->status, $state->all()));
                return $lastResult;
            }

            $previousNodeId = $currentNodeId;
            $currentNodeId = $lastResult->nextNodeId
                ?? $compiled->resolveNextNodeId($currentNodeId, $state);
        }

        $this->dispatchEventSafely(new GraphCompleted($runId, $lastResult->status, $state->all()));

        return $lastResult;
    }

    private function recordNodeMetric(GraphState $state, string $nodeId, string $status, int $startedAtNs): void
    {
        try {
            $metrics = app(OtelMetricsService::class);
        } catch (\Throwable) {
            return;
        }

        $durationMs = (hrtime(true) - $startedAtNs) / 1_000_000;
        $graphName = (string) $state->get('graph_name', 'process_analysis');
        $model = (string) ($state->get('ai_model_id') ?: $state->get('map_model_id') ?: 'n/a');
        $costUsd = (float) $state->get('cost_usd', 0);
        $tokens = (int) $state->get('total_tokens', 0);

        $metrics->recordPipelineNodeExecution(
            graphName: $graphName,
            nodeId: $nodeId,
            status: $status,
            durationMs: $durationMs,
            model: $model,
            costUsd: $costUsd,
            totalTokens: $tokens,
        );
    }

    private function dispatchEventSafely(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable) {
        }
    }
}
