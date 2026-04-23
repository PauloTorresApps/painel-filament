<?php

use App\Evaluation\Metrics\EntityRecallMetric;
use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;

test('entity recall metric passes when expected entities are present', function () {
    $analysis = new DocumentAnalysis([
        'ai_analysis' => 'Partes: joao da silva e banco acme. Valor discutido de R$ 12.300,00.',
    ]);

    $analysis->setRelation('microAnalyses', collect([
        new DocumentMicroAnalysis([
            'reduce_level' => 0,
            'aggregated_entities' => [
                'partes_mencionadas' => ['Joao da Silva', 'Banco Acme'],
                'valores_monetarios' => ['R$ 12.300,00'],
            ],
        ]),
    ]));

    $result = (new EntityRecallMetric())->evaluate($analysis);

    expect($result->passed)->toBeTrue()
        ->and($result->score)->toBe(1.0);
});
