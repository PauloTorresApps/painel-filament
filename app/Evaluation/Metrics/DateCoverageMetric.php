<?php

namespace App\Evaluation\Metrics;

use App\Evaluation\Contracts\MetricInterface;
use App\Evaluation\MetricResult;
use App\Evaluation\Metrics\Support\DateParser;
use App\Evaluation\Support\EvaluationConfig;
use App\Models\DocumentAnalysis;

class DateCoverageMetric implements MetricInterface
{
    public function evaluate(DocumentAnalysis $analysis): MetricResult
    {
        $threshold = (float) EvaluationConfig::get('evaluation.thresholds.date_coverage', 1.0);
        $opinionDates = DateParser::extractNormalizedDates($analysis->ai_analysis ?? '');

        $microAnalyses = $analysis->relationLoaded('microAnalyses')
            ? $analysis->microAnalyses
            : $analysis->microAnalyses()->where('reduce_level', 0)->get();

        $expectedMap = [];
        foreach ($microAnalyses as $micro) {
            if (($micro->reduce_level ?? 0) !== 0) {
                continue;
            }

            $events = $micro->timeline_events['eventos'] ?? [];
            foreach ($events as $event) {
                $raw = $event['data'] ?? ($event['data_original'] ?? null);
                if (!$raw) {
                    continue;
                }

                $normalized = DateParser::normalizeDate((string) $raw);
                if ($normalized) {
                    $expectedMap[$normalized] = true;
                }
            }
        }

        $expectedDates = array_keys($expectedMap);
        if (empty($expectedDates)) {
            return new MetricResult(static::class, 1.0, $threshold, true, [
                'expected_dates' => [],
                'missing_dates' => [],
                'found_dates' => $opinionDates,
            ]);
        }

        $opinionLookup = array_fill_keys($opinionDates, true);
        $missing = [];

        foreach ($expectedDates as $date) {
            if (!isset($opinionLookup[$date])) {
                $missing[] = $date;
            }
        }

        $matchedCount = count($expectedDates) - count($missing);
        $score = $matchedCount / count($expectedDates);

        return new MetricResult(
            static::class,
            $score,
            $threshold,
            $score >= $threshold,
            [
                'expected_dates' => $expectedDates,
                'missing_dates' => $missing,
                'found_dates' => $opinionDates,
            ]
        );
    }
}
