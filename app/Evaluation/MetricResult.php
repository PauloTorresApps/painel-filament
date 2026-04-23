<?php

namespace App\Evaluation;

class MetricResult
{
    public function __construct(
        public string $metricName,
        public float $score,
        public float $threshold,
        public bool $passed,
        public array $evidence = [],
        public string $layer = 'deterministic'
    ) {
    }
}
