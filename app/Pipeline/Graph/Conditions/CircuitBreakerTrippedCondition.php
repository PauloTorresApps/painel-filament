<?php

namespace App\Pipeline\Graph\Conditions;

use App\Pipeline\Graph\GraphState;

final class CircuitBreakerTrippedCondition
{
    public function evaluate(int $failedJobs, int $totalJobs, ?float $threshold = null, ?int $minJobs = null): bool
    {
        $safeThreshold = $threshold ?? (float) config('analysis.circuit_breaker.failure_threshold', 0.25);
        $safeMinJobs = $minJobs ?? (int) config('analysis.circuit_breaker.min_jobs', 4);

        if ($totalJobs < $safeMinJobs || $totalJobs <= 0) {
            return false;
        }

        return ($failedJobs / $totalJobs) > $safeThreshold;
    }

    public function __invoke(GraphState $state): bool
    {
        return $this->evaluate(
            failedJobs: (int) $state->get('failed_jobs', 0),
            totalJobs: (int) $state->get('total_jobs', 0),
            threshold: $state->get('circuit_breaker_threshold'),
            minJobs: $state->get('circuit_breaker_min_jobs')
        );
    }
}
