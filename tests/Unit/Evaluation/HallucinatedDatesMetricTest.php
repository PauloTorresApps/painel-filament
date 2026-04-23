<?php

use App\Evaluation\Metrics\HallucinatedDatesMetric;
use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;

test('hallucinated dates metric fails when opinion contains unknown date', function () {
    $analysis = new DocumentAnalysis([
        'ai_analysis' => 'Cronologia: 12/03/2024 e 31/12/2099.',
    ]);

    $analysis->setRelation('microAnalyses', collect([
        new DocumentMicroAnalysis([
            'reduce_level' => 0,
            'timeline_events' => [
                'eventos' => [
                    ['data' => '12/03/2024', 'descricao' => 'Protocolo inicial'],
                ],
            ],
            'micro_analysis' => 'Houve petição em 12/03/2024.',
            'extracted_text' => 'Documento datado de 12/03/2024.',
        ]),
    ]));

    $result = (new HallucinatedDatesMetric())->evaluate($analysis);

    expect($result->passed)->toBeFalse()
        ->and($result->evidence['invented_dates'])->toContain('2099-12-31');
});
