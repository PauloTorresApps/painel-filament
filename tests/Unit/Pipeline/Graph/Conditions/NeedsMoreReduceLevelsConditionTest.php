<?php

use App\Pipeline\Graph\Conditions\NeedsMoreReduceLevelsCondition;

uses(Tests\TestCase::class);

it('determines when hierarchical reduce needs additional level', function () {
    config()->set('analysis.reduce.batch_size', 10);
    config()->set('analysis.reduce.max_levels', 5);

    $condition = new NeedsMoreReduceLevelsCondition();

    expect($condition->evaluate(11, 1))->toBeTrue()
        ->and($condition->evaluate(10, 1))->toBeFalse()
        ->and($condition->evaluate(11, 5))->toBeFalse();
});
