<?php

use App\Models\DocumentAnalysis;
use App\Models\User;
use App\Pipeline\Graph\Checkpointers\DocumentAnalysisCheckpointer;
use App\Pipeline\Graph\GraphState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('persists and reloads graph checkpoint state on document analysis', function () {
    $user = User::factory()->create();

    $analysis = DocumentAnalysis::create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-00.2026.8.21.0001',
        'status' => 'processing',
    ]);

    $state = GraphState::fromArray([
        'analysis_id' => $analysis->id,
        'foo' => 'bar',
    ]);

    $runId = '00000000-0000-4000-8000-000000000123';

    $checkpointer = new DocumentAnalysisCheckpointer();
    $checkpointer->save($runId, 'inventory', $state);

    $loaded = $checkpointer->load($runId);

    expect($loaded)->not->toBeNull()
        ->and($loaded?->get('analysis_id'))->toBe($analysis->id)
        ->and($loaded?->get('foo'))->toBe('bar')
        ->and($checkpointer->lastNode($runId))->toBe('inventory');
});
