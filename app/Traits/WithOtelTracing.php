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
        if (!class_exists(Globals::class)) {
            return [null, null];
        }

        try {
            $tracer = Globals::tracerProvider()->getTracer($instrumentationName);
            $span = $tracer->spanBuilder($spanName)->startSpan();
        } catch (\Throwable) {
            return [null, null];
        }

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
        if ($span === null) {
            return;
        }

        $span->setStatus(StatusCode::STATUS_OK);
        $span->end();
    }

    protected function finishSpanError(mixed $span, \Throwable $exception): void
    {
        if ($span === null) {
            return;
        }

        $span->recordException($exception);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        $span->end();
    }

    protected function detachScope(mixed $scope): void
    {
        if ($scope === null) {
            return;
        }

        $scope->detach();
    }
}
