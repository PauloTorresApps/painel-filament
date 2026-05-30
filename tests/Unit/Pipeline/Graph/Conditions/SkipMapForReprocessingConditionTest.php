<?php

use App\Pipeline\Graph\Conditions\SkipMapForReprocessingCondition;

it('detects skip map scenario for reprocessing', function () {
    $condition = new SkipMapForReprocessingCondition();

    expect($condition->evaluate(0, 1))->toBeTrue()
        ->and($condition->evaluate(1, 1))->toBeFalse()
        ->and($condition->evaluate(0, 0))->toBeFalse();
});
