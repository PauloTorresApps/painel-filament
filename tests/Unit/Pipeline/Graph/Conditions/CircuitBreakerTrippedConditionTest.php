<?php

use App\Pipeline\Graph\Conditions\CircuitBreakerTrippedCondition;

uses(Tests\TestCase::class);

it('trips only when threshold and minimum jobs are exceeded', function () {
    config()->set('analysis.circuit_breaker.failure_threshold', 0.25);
    config()->set('analysis.circuit_breaker.min_jobs', 4);

    $condition = new CircuitBreakerTrippedCondition();

    expect($condition->evaluate(2, 8))->toBeFalse()
        ->and($condition->evaluate(3, 8))->toBeTrue()
        ->and($condition->evaluate(1, 3))->toBeFalse();
});
