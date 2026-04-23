<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Evaluation Pipeline
    |--------------------------------------------------------------------------
    */

    'enabled' => (bool) env('EVALUATION_ENABLED', true),

    'metrics' => [
        App\Evaluation\Metrics\EntityRecallMetric::class,
        App\Evaluation\Metrics\DateCoverageMetric::class,
        App\Evaluation\Metrics\HallucinatedDatesMetric::class,
        App\Evaluation\Metrics\ProcessNumberIntegrityMetric::class,
        App\Evaluation\Metrics\StructuralCompletenessMetric::class,
    ],

    'thresholds' => [
        'entity_recall' => (float) env('EVAL_ENTITY_RECALL_THRESHOLD', 0.90),
        'date_coverage' => (float) env('EVAL_DATE_COVERAGE_THRESHOLD', 1.00),
        'hallucinated_dates_compliance' => (float) env('EVAL_HALLUCINATED_DATES_THRESHOLD', 1.00),
        'process_number_integrity' => (float) env('EVAL_PROCESS_NUMBER_THRESHOLD', 1.00),
        'structural_completeness' => (float) env('EVAL_STRUCTURAL_COMPLETENESS_THRESHOLD', 0.75),
    ],

    'required_sections' => [
        'partes',
        'pedidos',
        'cronologia',
        'conclusao',
    ],

    'critical_metrics' => [
        App\Evaluation\Metrics\HallucinatedDatesMetric::class,
        App\Evaluation\Metrics\ProcessNumberIntegrityMetric::class,
    ],

    'review' => [
        'minimum_overall_score' => (float) env('EVAL_MIN_OVERALL_SCORE', 0.85),
    ],

];
