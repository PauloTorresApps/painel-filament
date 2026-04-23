<?php

namespace App\Evaluation\Metrics;

use App\Evaluation\Contracts\MetricInterface;
use App\Evaluation\MetricResult;
use App\Evaluation\Support\EvaluationConfig;
use App\Models\DocumentAnalysis;

class StructuralCompletenessMetric implements MetricInterface
{
    public function evaluate(DocumentAnalysis $analysis): MetricResult
    {
        $threshold = (float) EvaluationConfig::get('evaluation.thresholds.structural_completeness', 0.75);
        $requiredSections = (array) EvaluationConfig::get('evaluation.required_sections', []);

        if (empty($requiredSections)) {
            return new MetricResult(static::class, 1.0, $threshold, true, [
                'required_sections' => [],
                'missing_sections' => [],
            ]);
        }

        $text = mb_strtolower((string) $analysis->ai_analysis);
        $found = [];
        $missing = [];

        foreach ($requiredSections as $section) {
            $section = mb_strtolower(trim((string) $section));
            if ($section === '') {
                continue;
            }

            if (str_contains($text, $section)) {
                $found[] = $section;
            } else {
                $missing[] = $section;
            }
        }

        $requiredCount = max(1, count($found) + count($missing));
        $score = count($found) / $requiredCount;

        return new MetricResult(
            static::class,
            $score,
            $threshold,
            $score >= $threshold,
            [
                'required_sections' => array_values($requiredSections),
                'found_sections' => $found,
                'missing_sections' => $missing,
            ]
        );
    }
}
