<?php

namespace App\Evaluation\Metrics;

use App\Evaluation\Contracts\MetricInterface;
use App\Evaluation\MetricResult;
use App\Evaluation\Metrics\Support\DateParser;
use App\Evaluation\Support\EvaluationConfig;
use App\Models\DocumentAnalysis;

class HallucinatedDatesMetric implements MetricInterface
{
    public function evaluate(DocumentAnalysis $analysis): MetricResult
    {
        $threshold = (float) EvaluationConfig::get('evaluation.thresholds.hallucinated_dates_compliance', 1.0);
        $opinionDates = DateParser::extractNormalizedDates($analysis->ai_analysis ?? '');

        $microAnalyses = $analysis->relationLoaded('microAnalyses')
            ? $analysis->microAnalyses
            : $analysis->microAnalyses()->where('reduce_level', 0)->get();

        $expectedMap = [];
        foreach ($microAnalyses as $micro) {
            if (($micro->reduce_level ?? 0) !== 0) {
                continue;
            }

            foreach (DateParser::extractNormalizedDates((string) ($micro->micro_analysis ?? '')) as $date) {
                $expectedMap[$date] = true;
            }

            foreach (DateParser::extractNormalizedDates((string) ($micro->extracted_text ?? '')) as $date) {
                $expectedMap[$date] = true;
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

        if (empty($opinionDates)) {
            return new MetricResult(static::class, 1.0, $threshold, true, [
                'invented_dates' => [],
                'opinion_dates_count' => 0,
            ]);
        }

        $invented = [];
        foreach ($opinionDates as $date) {
            if (!isset($expectedMap[$date])) {
                $invented[] = $date;
            }
        }

        $inventedRatio = count($invented) / count($opinionDates);
        $compliance = max(0.0, 1.0 - $inventedRatio);

        return new MetricResult(
            static::class,
            $compliance,
            $threshold,
            $compliance >= $threshold,
            [
                'invented_dates' => $invented,
                'opinion_dates_count' => count($opinionDates),
                'known_dates_count' => count($expectedMap),
            ]
        );
    }
}
