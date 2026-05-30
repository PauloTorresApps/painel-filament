<?php

use App\Pipeline\Graph\Contracts\Node;
use App\Pipeline\Graph\Graph;
use App\Pipeline\Graph\GraphRunner;
use App\Pipeline\Graph\GraphState;
use App\Pipeline\Graph\NodeResult;

it('executes graph in sequence and applies state updates', function () {
    $graph = (new Graph())
        ->node(new class implements Node {
            public function id(): string { return 'start'; }
            public function run(GraphState $state): NodeResult
            {
                return NodeResult::continue(stateUpdates: ['step' => 'start']);
            }
        })
        ->node(new class implements Node {
            public function id(): string { return 'finish'; }
            public function run(GraphState $state): NodeResult
            {
                return NodeResult::halt(['done' => $state->get('step') === 'start']);
            }
        })
        ->edge('start', 'finish');

    $result = (new GraphRunner())->runGraph($graph, GraphState::fromArray([]));

    expect($result->status)->toBe('halt')
        ->and($result->stateUpdates)->toBe(['done' => true]);
});

it('prefers node-provided next edge when present', function () {
    $graph = (new Graph())
        ->node(new class implements Node {
            public function id(): string { return 'start'; }
            public function run(GraphState $state): NodeResult
            {
                return NodeResult::continue(nextNodeId: 'alt');
            }
        })
        ->node(new class implements Node {
            public function id(): string { return 'main'; }
            public function run(GraphState $state): NodeResult { return NodeResult::fail(); }
        })
        ->node(new class implements Node {
            public function id(): string { return 'alt'; }
            public function run(GraphState $state): NodeResult { return NodeResult::halt(); }
        })
        ->edge('start', 'main')
        ->edge('main', 'alt');

    $result = (new GraphRunner())->runGraph($graph, GraphState::fromArray([]));

    expect($result->status)->toBe('halt');
});

it('stops graph execution at async dispatched boundary', function () {
    $graph = (new Graph())
        ->node(new class implements Node {
            public function id(): string { return 'start'; }
            public function run(GraphState $state): NodeResult
            {
                return NodeResult::dispatched(nextNodeId: 'next');
            }
        })
        ->node(new class implements Node {
            public function id(): string { return 'next'; }
            public function run(GraphState $state): NodeResult
            {
                return NodeResult::fail();
            }
        })
        ->edge('start', 'next');

    $result = (new GraphRunner())->runGraph($graph, GraphState::fromArray([]));

    expect($result->status)->toBe('dispatched');
});
