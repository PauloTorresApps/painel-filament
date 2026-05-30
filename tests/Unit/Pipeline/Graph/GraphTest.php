<?php

use App\Pipeline\Graph\Contracts\Node;
use App\Pipeline\Graph\Graph;
use App\Pipeline\Graph\GraphState;
use App\Pipeline\Graph\NodeResult;
use InvalidArgumentException;

it('compiles a valid graph and resolves conditional edges', function () {
    $graph = (new Graph())
        ->node(new class implements Node {
            public function id(): string { return 'start'; }
            public function run(GraphState $state): NodeResult { return NodeResult::continue(); }
        })
        ->node(new class implements Node {
            public function id(): string { return 'high'; }
            public function run(GraphState $state): NodeResult { return NodeResult::halt(); }
        })
        ->node(new class implements Node {
            public function id(): string { return 'low'; }
            public function run(GraphState $state): NodeResult { return NodeResult::halt(); }
        })
        ->conditionalEdge('start', [
            'high' => fn (GraphState $state) => (int) $state->get('score', 0) >= 60,
            'low' => fn (GraphState $state) => (int) $state->get('score', 0) < 60,
        ]);

    $graph->compile();

    expect($graph->resolveNextNodeId('start', GraphState::fromArray(['score' => 80])))->toBe('high')
        ->and($graph->resolveNextNodeId('start', GraphState::fromArray(['score' => 10])))->toBe('low');
});

it('fails graph compilation when cycle exists', function () {
    $graph = (new Graph())
        ->node(new class implements Node {
            public function id(): string { return 'a'; }
            public function run(GraphState $state): NodeResult { return NodeResult::continue(); }
        })
        ->node(new class implements Node {
            public function id(): string { return 'b'; }
            public function run(GraphState $state): NodeResult { return NodeResult::continue(); }
        })
        ->edge('a', 'b')
        ->edge('b', 'a');

    expect(fn () => $graph->compile())
        ->toThrow(InvalidArgumentException::class, 'cycle');
});

it('fails graph compilation when node is unreachable from entry', function () {
    $graph = (new Graph())
        ->node(new class implements Node {
            public function id(): string { return 'start'; }
            public function run(GraphState $state): NodeResult { return NodeResult::continue(); }
        })
        ->node(new class implements Node {
            public function id(): string { return 'end'; }
            public function run(GraphState $state): NodeResult { return NodeResult::halt(); }
        })
        ->node(new class implements Node {
            public function id(): string { return 'orphan'; }
            public function run(GraphState $state): NodeResult { return NodeResult::halt(); }
        })
        ->edge('start', 'end');

    expect(fn () => $graph->compile())
        ->toThrow(InvalidArgumentException::class, 'unreachable');
});
