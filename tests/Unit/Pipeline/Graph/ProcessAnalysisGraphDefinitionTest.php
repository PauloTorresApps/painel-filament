<?php

use App\Pipeline\Graph\Definitions\ProcessAnalysisGraph;
use App\Pipeline\Graph\GraphState;

uses(Tests\TestCase::class);

it('builds and compiles the process analysis graph definition', function () {
    $graph = ProcessAnalysisGraph::make();

    expect(array_keys($graph->nodes()))->toBe([
        'inventory',
        'map_dispatch',
        'refine_reduce',
        'batch_reduce',
        'chronology',
        'engine',
        'structured_opinion',
        'designer_brief',
    ]);
});

it('routes map dispatch to batch or refine according to reduce strategy', function () {
    $graph = ProcessAnalysisGraph::make();

    $batchNext = $graph->resolveNextNodeId('map_dispatch', GraphState::fromArray([
        'reduce_strategy' => 'batch',
    ]));

    $refineNext = $graph->resolveNextNodeId('map_dispatch', GraphState::fromArray([
        'reduce_strategy' => 'auto',
    ]));

    expect($batchNext)->toBe('batch_reduce')
        ->and($refineNext)->toBe('refine_reduce');
});
