<?php

namespace App\Evaluation\Metrics;

use App\Evaluation\Contracts\MetricInterface;
use App\Evaluation\MetricResult;
use App\Evaluation\Support\EvaluationConfig;
use App\Models\DocumentAnalysis;

class EntityRecallMetric implements MetricInterface
{
    public function evaluate(DocumentAnalysis $analysis): MetricResult
    {
        $opinion = mb_strtolower($analysis->ai_analysis ?? '');
        $threshold = (float) EvaluationConfig::get('evaluation.thresholds.entity_recall', 0.90);

        $expected = [];
        $microAnalyses = $analysis->relationLoaded('microAnalyses')
            ? $analysis->microAnalyses
            : $analysis->microAnalyses()->where('reduce_level', 0)->get();

        foreach ($microAnalyses as $micro) {
            if (($micro->reduce_level ?? 0) !== 0) {
                continue;
            }

            $entities = $micro->aggregated_entities ?? [];
            $parts = array_merge(
                $entities['partes_mencionadas'] ?? [],
                $entities['valores_monetarios'] ?? []
            );

            foreach ($parts as $term) {
                $term = $this->normalizeTerm((string) $term);
                if ($term !== '') {
                    $expected[$term] = true;
                }
            }
        }

        $expectedTerms = array_keys($expected);
        if (empty($expectedTerms)) {
            return new MetricResult(static::class, 1.0, $threshold, true, [
                'expected_count' => 0,
                'matched_count' => 0,
                'missing_terms' => [],
            ]);
        }

        $matched = [];
        $missing = [];

        foreach ($expectedTerms as $term) {
            if (str_contains($opinion, $term)) {
                $matched[] = $term;
            } else {
                $missing[] = $term;
            }
        }

        $score = count($matched) / count($expectedTerms);

        return new MetricResult(
            static::class,
            $score,
            $threshold,
            $score >= $threshold,
            [
                'expected_count' => count($expectedTerms),
                'matched_count' => count($matched),
                'missing_terms' => array_slice($missing, 0, 30),
            ]
        );
    }

    private function normalizeTerm(string $term): string
    {
        $term = mb_strtolower(trim($term));
        $term = preg_replace('/\s+/u', ' ', $term) ?? '';

        return $term;
    }
}
