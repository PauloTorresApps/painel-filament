<?php

namespace App\Traits;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\StatusCode;

trait WithOtelTracing
{
    /**
     * @return array{0: mixed, 1: mixed}
     */
    protected function startSpan(string $instrumentationName, string $spanName, array $attributes = []): array
    {
        $tracer = Globals::tracerProvider()->getTracer($instrumentationName);
        $span = $tracer->spanBuilder($spanName)->startSpan();

        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }

            $span->setAttribute($key, $value);
        }

        $scope = $span->activate();

        return [$span, $scope];
    }

    protected function finishSpanSuccess(mixed $span): void
    {
        $span->setStatus(StatusCode::STATUS_OK);
        $span->end();
    }

    protected function finishSpanError(mixed $span, \Throwable $exception): void
    {
        $span->recordException($exception);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        $span->end();
    }

    protected function detachScope(mixed $scope): void
    {
        $scope->detach();
    }
}
