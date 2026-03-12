<?php

namespace App\Jobs\Middleware;

use App\Services\OtelMetricsService;
use App\Traits\WithOtelTracing;
use Closure;

class OtelJobMiddleware
{
    use WithOtelTracing;

    public function handle(mixed $job, Closure $next): void
    {
        $jobName = class_basename($job);
        $queueName = (string) ($job->queue ?? 'default');
        $connectionName = (string) ($job->connection ?? config('queue.default', 'default'));
        $attempts = (int) ($job->attempts ?? 1);

        $attributes = [
            'job.name' => $jobName,
            'queue.name' => $queueName,
            'queue.connection' => $connectionName,
            'job.attempts' => $attempts,
        ];

        if (property_exists($job, 'documentAnalysisId')) {
            $attributes['analysis.id'] = (int) $job->documentAnalysisId;
        }

        if (property_exists($job, 'contractAnalysisId')) {
            $attributes['contract.analysis.id'] = (int) $job->contractAnalysisId;
        }

        if (property_exists($job, 'microAnalysisId')) {
            $attributes['micro.analysis.id'] = (int) $job->microAnalysisId;
        }

        [$span, $scope] = $this->startSpan(
            'painel-laravel-jobs',
            "job.{$jobName}",
            $attributes
        );

        $start = hrtime(true);
        $metrics = app(OtelMetricsService::class);

        try {
            $next($job);

            $durationMs = (hrtime(true) - $start) / 1_000_000;
            $metrics->recordJobExecution($jobName, 'success', $queueName, $durationMs);
            $this->finishSpanSuccess($span);
        } catch (\Throwable $exception) {
            $durationMs = (hrtime(true) - $start) / 1_000_000;
            $metrics->recordJobExecution($jobName, 'failed', $queueName, $durationMs);
            $this->finishSpanError($span, $exception);

            throw $exception;
        } finally {
            $this->detachScope($scope);
        }
    }
}
