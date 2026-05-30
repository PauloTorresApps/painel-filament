<?php

use App\Jobs\ProcessAnalysis\BuildInventoryJob;
use App\Models\DocumentAnalysis;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('resumes pipeline from informed node and dispatches its job', function () {
    Queue::fake();

    $user = User::factory()->create();

    $analysis = DocumentAnalysis::create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-00.2026.8.21.0004',
        'status' => 'processing',
        'job_parameters' => [
            'contextoDados' => ['classeProcessual' => 'Teste'],
            'aiProvider' => 'openrouter',
            'deepThinkingEnabled' => false,
            'aiModelId' => 'anthropic/claude-sonnet-4',
            'mapModelId' => 'google/gemini-2.5-pro',
            'reduceStrategy' => 'auto',
            'promptTemplate' => 'Prompt final',
        ],
    ]);

    $this->artisan("pipeline:resume {$analysis->id} --from=inventory")
        ->expectsOutput("analysis_id: {$analysis->id}")
        ->expectsOutput('start_node: inventory')
        ->expectsOutput('result_status: dispatched')
        ->assertSuccessful();

    Queue::assertPushed(BuildInventoryJob::class);
});
