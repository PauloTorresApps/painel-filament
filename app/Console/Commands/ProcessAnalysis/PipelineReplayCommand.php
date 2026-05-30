<?php

namespace App\Console\Commands\ProcessAnalysis;

use App\Models\DocumentAnalysis;
use App\Pipeline\Graph\Checkpointers\PipelineRunCheckpointer;
use App\Pipeline\Graph\Definitions\ProcessAnalysisGraph;
use App\Pipeline\Graph\GraphRunner;
use App\Pipeline\Graph\ProcessAnalysisGraphStateFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PipelineReplayCommand extends Command
{
    protected $signature = 'pipeline:replay {analysis_id : ID da document_analysis} {--node= : Node para reexecucao}';

    protected $description = 'Reexecuta um node especifico no grafo process_analysis para debug/replay controlado.';

    public function handle(): int
    {
        $analysisId = (int) $this->argument('analysis_id');
        $analysis = DocumentAnalysis::find($analysisId);

        if (!$analysis) {
            $this->error("DocumentAnalysis [{$analysisId}] nao encontrada.");
            return self::FAILURE;
        }

        $node = (string) $this->option('node');

        if ($node === '') {
            $this->error('Informe --node=NODE para replay.');
            return self::FAILURE;
        }

        $graph = ProcessAnalysisGraph::make();

        try {
            $graph->nodeById($node);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $state = (new ProcessAnalysisGraphStateFactory())
            ->fromDocumentAnalysis($analysis)
            ->merge([
                'graph_status' => 'running',
                'graph_replay' => true,
                'graph_run_id' => $analysis->graph_run_id ?: (string) Str::uuid(),
            ]);

        $result = (new GraphRunner())->runGraph(
            graph: $graph,
            initialState: $state,
            checkpointer: new PipelineRunCheckpointer(),
            startNodeId: $node,
        );

        $this->line("analysis_id: {$analysis->id}");
        $this->line("replayed_node: {$node}");
        $this->line('result_status: ' . $result->status);

        return self::SUCCESS;
    }
}
