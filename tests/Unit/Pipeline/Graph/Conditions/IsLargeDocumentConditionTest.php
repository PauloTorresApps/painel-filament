<?php

use App\Pipeline\Graph\Conditions\IsLargeDocumentCondition;

uses(Tests\TestCase::class);

it('detects large document by configured threshold', function () {
    config()->set('analysis.thresholds.large_document_chars', 1000);

    $condition = new IsLargeDocumentCondition();

    expect($condition->evaluate(1001))->toBeTrue()
        ->and($condition->evaluate(1000))->toBeFalse();
});
