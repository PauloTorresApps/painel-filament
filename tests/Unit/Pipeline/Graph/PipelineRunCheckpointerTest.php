<?php

use App\Models\DocumentAnalysis;
use App\Models\PipelineRun;
use App\Models\User;
use App\Pipeline\Graph\Checkpointers\PipelineRunCheckpointer;
use App\Pipeline\Graph\GraphState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('persists checkpoint in pipeline_runs and syncs document_analysis fields', function () {
    $user = User::factory()->create();

    $analysis = DocumentAnalysis::create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-00.2026.8.21.0002',
        'status' => 'processing',
    ]);

    $runId = '00000000-0000-4000-8000-000000000222';
    $state = GraphState::fromArray([
        'analysis_id' => $analysis->id,
        'graph_name' => 'process_analysis',
        'graph_status' => 'running',
    ]);

    $checkpointer = new PipelineRunCheckpointer();
    $checkpointer->save($runId, 'inventory', $state);

    $run = PipelineRun::find($runId);
    $analysis->refresh();

    expect($run)->not->toBeNull()
        ->and($run?->current_node)->toBe('inventory')
        ->and($analysis->graph_run_id)->toBe($runId)
        ->and($analysis->graph_last_node)->toBe('inventory')
        ->and($checkpointer->lastNode($runId))->toBe('inventory')
        ->and($checkpointer->load($runId)?->get('analysis_id'))->toBe($analysis->id);
});
