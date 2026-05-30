<?php

namespace App\Console\Commands\ProcessAnalysis;

use App\Models\DocumentAnalysis;
use App\Models\PipelineRun;
use Illuminate\Console\Command;

class PipelineStatusCommand extends Command
{
    protected $signature = 'pipeline:status {analysis_id : ID da document_analysis}';

    protected $description = 'Mostra status do pipeline e ultimo checkpoint do grafo para uma analise.';

    public function handle(): int
    {
        $analysisId = (int) $this->argument('analysis_id');
        $analysis = DocumentAnalysis::find($analysisId);

        if (!$analysis) {
            $this->error("DocumentAnalysis [{$analysisId}] nao encontrada.");
            return self::FAILURE;
        }

        $run = null;
        if (!empty($analysis->graph_run_id)) {
            $run = PipelineRun::find((string) $analysis->graph_run_id);
        }

        if (!$run) {
            $run = PipelineRun::query()
                ->where('entity_type', 'document_analysis')
                ->where('entity_id', $analysisId)
                ->latest('created_at')
                ->first();
        }

        $this->line("analysis_id: {$analysis->id}");
        $this->line("status: {$analysis->status}");
        $this->line("current_phase: {$analysis->current_phase}");
        $this->line('graph_enabled: ' . ((bool) config('analysis.graph_runner.enabled', false) ? 'true' : 'false'));

        if (!$run) {
            $this->line('pipeline_run: none');
            $this->line('graph_last_node: ' . ($analysis->graph_last_node ?? 'none'));
            return self::SUCCESS;
        }

        $state = is_array($run->state) ? $run->state : [];

        $this->line("pipeline_run_id: {$run->id}");
        $this->line("pipeline_graph: {$run->graph_name}");
        $this->line("pipeline_status: {$run->status}");
        $this->line('pipeline_current_node: ' . ($run->current_node ?? 'none'));
        $this->line('state_keys: ' . implode(', ', array_keys($state)));

        return self::SUCCESS;
    }
}
