<?php

use App\Models\DocumentAnalysis;
use App\Models\PipelineRun;
use App\Models\User;

it('shows pipeline status with latest run data', function () {
    $user = User::factory()->create();

    $analysis = DocumentAnalysis::create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-00.2026.8.21.0003',
        'status' => 'processing',
        'current_phase' => 'map',
        'graph_run_id' => '00000000-0000-4000-8000-000000000333',
        'graph_last_node' => 'map_dispatch',
        'graph_state' => ['analysis_id' => 1],
    ]);

    PipelineRun::create([
        'id' => '00000000-0000-4000-8000-000000000333',
        'graph_name' => 'process_analysis',
        'entity_type' => 'document_analysis',
        'entity_id' => $analysis->id,
        'status' => 'running',
        'current_node' => 'map_dispatch',
        'state' => ['analysis_id' => $analysis->id, 'reduce_strategy' => 'auto'],
    ]);

    $this->artisan("pipeline:status {$analysis->id}")
        ->expectsOutput("analysis_id: {$analysis->id}")
        ->expectsOutput('pipeline_graph: process_analysis')
        ->expectsOutput('pipeline_current_node: map_dispatch')
        ->assertSuccessful();
});
