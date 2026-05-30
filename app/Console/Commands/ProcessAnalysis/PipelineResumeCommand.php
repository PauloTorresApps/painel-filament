<?php

namespace App\Console\Commands\ProcessAnalysis;

use App\Models\DocumentAnalysis;
use App\Pipeline\Graph\Checkpointers\PipelineRunCheckpointer;
use App\Pipeline\Graph\Definitions\ProcessAnalysisGraph;
use App\Pipeline\Graph\GraphRunner;
use App\Pipeline\Graph\ProcessAnalysisGraphStateFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PipelineResumeCommand extends Command
{
    protected $signature = 'pipeline:resume {analysis_id : ID da document_analysis} {--from= : Node inicial para retomada}';

    protected $description = 'Retoma o grafo process_analysis a partir do ultimo checkpoint ou do node informado.';

    public function handle(): int
    {
        $analysisId = (int) $this->argument('analysis_id');
        $analysis = DocumentAnalysis::find($analysisId);

        if (!$analysis) {
            $this->error("DocumentAnalysis [{$analysisId}] nao encontrada.");
            return self::FAILURE;
        }

        $graph = ProcessAnalysisGraph::make();
        $state = (new ProcessAnalysisGraphStateFactory())
            ->fromDocumentAnalysis($analysis)
            ->merge([
                'graph_status' => 'running',
                'graph_run_id' => $analysis->graph_run_id ?: (string) Str::uuid(),
            ]);

        $startNode = (string) ($this->option('from') ?: $analysis->graph_last_node ?: $graph->entryNodeId());

        try {
            $graph->nodeById($startNode);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $result = (new GraphRunner())->runGraph(
            graph: $graph,
            initialState: $state,
            checkpointer: new PipelineRunCheckpointer(),
            startNodeId: $startNode,
        );

        $this->line("analysis_id: {$analysis->id}");
        $this->line('start_node: ' . $startNode);
        $this->line('result_status: ' . $result->status);

        return self::SUCCESS;
    }
}
