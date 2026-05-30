<?php

use App\Pipeline\Graph\Conditions\UseRefineStrategyCondition;

uses(Tests\TestCase::class);

it('returns false when explicit strategy is batch', function () {
    $condition = new UseRefineStrategyCondition();

    expect($condition->evaluate(docCount: 1, totalChars: 100, reduceStrategy: 'batch'))->toBeFalse();
});

it('returns true when explicit strategy is refine', function () {
    $condition = new UseRefineStrategyCondition();

    expect($condition->evaluate(docCount: 200, totalChars: 9000000, reduceStrategy: 'refine'))->toBeTrue();
});

it('uses automatic thresholds for document count and chars', function () {
    config()->set('analysis.thresholds.refine_max_documents', 20);
    config()->set('analysis.reduce.direct_consolidation_chars', 2000000);

    $condition = new UseRefineStrategyCondition();

    expect($condition->evaluate(docCount: 10, totalChars: 500000, reduceStrategy: 'auto'))->toBeTrue()
        ->and($condition->evaluate(docCount: 21, totalChars: 500000, reduceStrategy: 'auto'))->toBeFalse()
        ->and($condition->evaluate(docCount: 10, totalChars: 2100000, reduceStrategy: 'auto'))->toBeFalse();
});
