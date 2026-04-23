<?php

namespace App\Evaluation\Metrics;

use App\Evaluation\Contracts\MetricInterface;
use App\Evaluation\MetricResult;
use App\Evaluation\Support\EvaluationConfig;
use App\Models\DocumentAnalysis;

class ProcessNumberIntegrityMetric implements MetricInterface
{
    public function evaluate(DocumentAnalysis $analysis): MetricResult
    {
        $threshold = (float) EvaluationConfig::get('evaluation.thresholds.process_number_integrity', 1.0);
        $processDigits = preg_replace('/\D+/', '', (string) $analysis->numero_processo) ?? '';
        $opinionDigits = preg_replace('/\D+/', '', (string) $analysis->ai_analysis) ?? '';

        $found = $processDigits !== '' && str_contains($opinionDigits, $processDigits);
        $score = $found ? 1.0 : 0.0;

        return new MetricResult(
            static::class,
            $score,
            $threshold,
            $score >= $threshold,
            [
                'process_digits' => $processDigits,
                'found_in_opinion' => $found,
            ]
        );
    }
}
