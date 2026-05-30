<?php

use App\Pipeline\Graph\Conditions\DirectConsolidationFitsCondition;

it('allows direct consolidation only when starting from zero and fitting the limit', function () {
    $condition = new DirectConsolidationFitsCondition();

    expect($condition->evaluate(startFromIndex: 0, totalChars: 1000, directConsolidationLimit: 1000))->toBeTrue()
        ->and($condition->evaluate(startFromIndex: 1, totalChars: 1000, directConsolidationLimit: 1000))->toBeFalse()
        ->and($condition->evaluate(startFromIndex: 0, totalChars: 1001, directConsolidationLimit: 1000))->toBeFalse();
});
