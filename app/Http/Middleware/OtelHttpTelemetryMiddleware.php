<?php

namespace App\Http\Middleware;

use App\Services\OtelMetricsService;
use Closure;
use Illuminate\Http\Request;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Component\HttpFoundation\Response;

class OtelHttpTelemetryMiddleware
{
    public function __construct(private readonly OtelMetricsService $otelMetricsService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $start = hrtime(true);

        $route = $request->route();
        $routeName = $route?->getName() ?? 'unnamed';
        $method = $request->method();
        $path = '/'.ltrim($request->path(), '/');

        $spanName = sprintf('%s %s', $method, $path === '/' ? '/' : $path);

        $span = null;
        $scope = null;

        if (class_exists(Globals::class)) {
            try {
                $tracer = Globals::tracerProvider()->getTracer('painel-laravel-http');
                $span = $tracer->spanBuilder($spanName)->startSpan();
                $scope = $span->activate();
            } catch (\Throwable) {
                $span = null;
                $scope = null;
            }
        }

        $statusCode = 500;

        try {
            /** @var Response $response */
            $response = $next($request);
            $statusCode = $response->getStatusCode();

            if ($span !== null) {
                $span->setAttribute('http.request.method', $method);
                $span->setAttribute('url.path', $path);
                $span->setAttribute('http.route', $routeName);
                $span->setAttribute('http.response.status_code', $statusCode);
                $span->setAttribute('server.address', $request->getHost());

                if ($statusCode >= 500) {
                    $span->setStatus(StatusCode::STATUS_ERROR, 'HTTP 5xx');
                } else {
                    $span->setStatus(StatusCode::STATUS_OK);
                }
            }

            return $response;
        } catch (\Throwable $exception) {
            if ($span !== null) {
                $span->recordException($exception);
                $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
            }

            throw $exception;
        } finally {
            $durationMs = (hrtime(true) - $start) / 1_000_000;
            try {
                $this->otelMetricsService->recordHttpRequest($method, $routeName, $statusCode, $durationMs);
            } catch (\Throwable) {
                // Nao interrompe o fluxo HTTP por indisponibilidade de telemetria.
            }

            if ($scope !== null) {
                $scope->detach();
            }

            if ($span !== null) {
                $span->end();
            }
        }
    }
}
