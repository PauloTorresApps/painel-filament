<?php

use App\Evaluation\Metrics\ProcessNumberIntegrityMetric;
use App\Models\DocumentAnalysis;

test('process number integrity metric passes when process number appears in opinion', function () {
    $analysis = new DocumentAnalysis([
        'numero_processo' => '5001234-56.2024.8.26.0100',
        'ai_analysis' => 'Parecer do processo 5001234-56.2024.8.26.0100 com base nos autos.',
    ]);

    $result = (new ProcessNumberIntegrityMetric())->evaluate($analysis);

    expect($result->passed)->toBeTrue()
        ->and($result->score)->toBe(1.0);
});
