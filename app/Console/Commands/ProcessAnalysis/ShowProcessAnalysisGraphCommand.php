<?php

namespace App\Console\Commands\ProcessAnalysis;

use App\Pipeline\Graph\Definitions\ProcessAnalysisGraph;
use Illuminate\Console\Command;

class ShowProcessAnalysisGraphCommand extends Command
{
    protected $signature = 'pipeline:graph:show {graph=process_analysis} {--format=mermaid}';

    protected $description = 'Exibe a definicao declarativa do grafo de pipeline.';

    public function handle(): int
    {
        $graphName = (string) $this->argument('graph');
        $format = (string) $this->option('format');

        if ($graphName !== 'process_analysis') {
            $this->error("Grafo [{$graphName}] nao suportado. Use process_analysis.");
            return self::FAILURE;
        }

        $graph = ProcessAnalysisGraph::make();

        if ($format !== 'mermaid') {
            $this->error("Formato [{$format}] nao suportado. Use --format=mermaid.");
            return self::FAILURE;
        }

        $this->line('graph TD');

        foreach ($graph->edges() as $edge) {
            $label = $edge->label ? '|'.$edge->label.'|' : '';
            $this->line("    {$edge->from} -->{$label} {$edge->to}");
        }

        return self::SUCCESS;
    }
}
