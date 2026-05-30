<?php

use App\Jobs\ProcessAnalysis\BuildInventoryJob;
use App\Pipeline\Graph\GraphRunner;
use App\Pipeline\Graph\GraphState;
use App\Pipeline\Graph\Nodes\InventoryNode;
use Illuminate\Support\Facades\Queue;

uses(Tests\TestCase::class);

it('dispatches build inventory job through inventory node', function () {
    Queue::fake();

    $state = GraphState::fromArray([
        'analysis_id' => 101,
        'ai_provider' => 'openrouter',
        'deep_thinking_enabled' => true,
        'contexto_dados' => ['classeProcessual' => 'Teste'],
        'ai_model_id' => 'anthropic/claude-sonnet-4',
        'user_id' => 15,
        'reduce_strategy' => 'auto',
        'map_model_id' => 'google/gemini-2.5-pro',
    ]);

    $result = (new GraphRunner())->run(new InventoryNode(), $state);

    Queue::assertPushed(BuildInventoryJob::class, function (BuildInventoryJob $job) {
        return $job->analysisId === 101
            && $job->aiProvider === 'openrouter'
            && $job->deepThinkingEnabled === true
            && $job->contextoDados === ['classeProcessual' => 'Teste']
            && $job->aiModelId === 'anthropic/claude-sonnet-4'
            && $job->userId === 15
            && $job->reduceStrategy === 'auto'
            && $job->mapModelId === 'google/gemini-2.5-pro';
    });

    expect($result->status)->toBe('dispatched')
        ->and($result->nextNodeId)->toBe('dispatch_map_phase');
});
