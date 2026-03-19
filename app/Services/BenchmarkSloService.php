<?php

namespace App\Services;

class BenchmarkSloService
{
    /**
     * @return array<int, array{key:string, actual:float|int, expected:float|int, comparator:string}>
     */
    public function evaluate(array $metrics): array
    {
        $breaches = [];

        $completedJudicial = (int) ($metrics['judicial']['completed_analyses'] ?? 0);
        $completedContracts = (int) ($metrics['contracts']['completed_analyses'] ?? 0);

        $rules = [
            ['key' => 'judicial.p95_total_ms', 'comparator' => '<=', 'actual' => (int) ($metrics['judicial']['p95_total_ms'] ?? 0), 'expected' => (int) config('analysis.slo.judicial.p95_total_ms_max', 0), 'skip_if_no_data' => $completedJudicial === 0],
            ['key' => 'judicial.docs_per_min', 'comparator' => '>=', 'actual' => (float) ($metrics['judicial']['docs_per_min'] ?? 0.0), 'expected' => (float) config('analysis.slo.judicial.docs_per_min_min', 0), 'skip_if_no_data' => $completedJudicial === 0],
            ['key' => 'contracts.avg_analysis_ms', 'comparator' => '<=', 'actual' => (int) ($metrics['contracts']['avg_analysis_ms'] ?? 0), 'expected' => (int) config('analysis.slo.contracts.avg_analysis_ms_max', 0), 'skip_if_no_data' => $completedContracts === 0],
            ['key' => 'contracts.avg_legal_opinion_ms', 'comparator' => '<=', 'actual' => (int) ($metrics['contracts']['avg_legal_opinion_ms'] ?? 0), 'expected' => (int) config('analysis.slo.contracts.avg_legal_opinion_ms_max', 0), 'skip_if_no_data' => $completedContracts === 0],
            ['key' => 'contracts.avg_infographic_ms', 'comparator' => '<=', 'actual' => (int) ($metrics['contracts']['avg_infographic_ms'] ?? 0), 'expected' => (int) config('analysis.slo.contracts.avg_infographic_ms_max', 0), 'skip_if_no_data' => $completedContracts === 0],
        ];

        foreach ($rules as $rule) {
            $expected = $rule['expected'];

            if ($expected <= 0 || $rule['skip_if_no_data']) {
                continue;
            }

            $isOk = $rule['comparator'] === '<='
                ? $rule['actual'] <= $expected
                : $rule['actual'] >= $expected;

            if (!$isOk) {
                $breaches[] = [
                    'key' => $rule['key'],
                    'actual' => $rule['actual'],
                    'expected' => $expected,
                    'comparator' => $rule['comparator'],
                ];
            }
        }

        return $breaches;
    }
}
