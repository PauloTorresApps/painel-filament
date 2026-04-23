<?php

namespace App\Evaluation;

use App\Evaluation\Support\EvaluationConfig;

class ScoreAggregator
{
    /**
     * @param MetricResult[] $results
     */
    public function aggregate(array $results): array
    {
        if (empty($results)) {
            return [
                'overall_score' => 0.0,
                'passed' => false,
                'verdict' => 'REJECTED',
                'failed_metrics' => [],
            ];
        }

        $failedMetrics = [];
        $sum = 0.0;
        $count = 0;

        foreach ($results as $result) {
            $sum += $result->score;
            $count++;
            if (!$result->passed) {
                $failedMetrics[] = $result->metricName;
            }
        }

        $overallScore = $count > 0 ? round($sum / $count, 4) : 0.0;
        $criticalMetrics = (array) EvaluationConfig::get('evaluation.critical_metrics', []);
        $criticalFailed = array_values(array_intersect($failedMetrics, $criticalMetrics));

        if (!empty($criticalFailed)) {
            return [
                'overall_score' => $overallScore,
                'passed' => false,
                'verdict' => 'REJECTED',
                'failed_metrics' => $failedMetrics,
                'failed_critical_metrics' => $criticalFailed,
            ];
        }

        if (!empty($failedMetrics)) {
            return [
                'overall_score' => $overallScore,
                'passed' => false,
                'verdict' => $overallScore >= (float) EvaluationConfig::get('evaluation.review.minimum_overall_score', 0.85) ? 'REVIEW' : 'REJECTED',
                'failed_metrics' => $failedMetrics,
                'failed_critical_metrics' => [],
            ];
        }

        return [
            'overall_score' => $overallScore,
            'passed' => true,
            'verdict' => 'APPROVED',
            'failed_metrics' => [],
            'failed_critical_metrics' => [],
        ];
    }
}
