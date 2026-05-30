<?php

use App\Jobs\ProcessAnalysis\BuildStructuredOpinionJob;
use App\Models\DocumentAnalysis;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('replays a specific node and dispatches expected job', function () {
    Queue::fake();

    $user = User::factory()->create();

    $analysis = DocumentAnalysis::create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-00.2026.8.21.0005',
        'status' => 'processing',
    ]);

    $this->artisan("pipeline:replay {$analysis->id} --node=structured_opinion")
        ->expectsOutput("analysis_id: {$analysis->id}")
        ->expectsOutput('replayed_node: structured_opinion')
        ->expectsOutput('result_status: dispatched')
        ->assertSuccessful();

    Queue::assertPushed(BuildStructuredOpinionJob::class);
});

it('fails replay command when node option is missing', function () {
    $user = User::factory()->create();

    $analysis = DocumentAnalysis::create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-00.2026.8.21.0006',
        'status' => 'processing',
    ]);

    $this->artisan("pipeline:replay {$analysis->id}")
        ->expectsOutput('Informe --node=NODE para replay.')
        ->assertFailed();
});
