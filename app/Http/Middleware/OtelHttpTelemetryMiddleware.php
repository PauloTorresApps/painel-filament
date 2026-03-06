<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Component\HttpFoundation\Response;

class OtelHttpTelemetryMiddleware
{
    private static $requestCounter = null;
    private static $requestDuration = null;

    public function handle(Request $request, Closure $next): Response
    {
        $start = hrtime(true);

        $route = $request->route();
        $routeName = $route?->getName() ?? 'unnamed';
        $method = $request->method();
        $path = '/'.ltrim($request->path(), '/');

        $spanName = sprintf('%s %s', $method, $path === '/' ? '/' : $path);

        $tracer = Globals::tracerProvider()->getTracer('painel-laravel-http');
        $span = $tracer->spanBuilder($spanName)->startSpan();
        $scope = $span->activate();

        $statusCode = 500;

        try {
            /** @var Response $response */
            $response = $next($request);
            $statusCode = $response->getStatusCode();

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

            return $response;
        } catch (\Throwable $exception) {
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());

            throw $exception;
        } finally {
            $durationMs = (hrtime(true) - $start) / 1_000_000;

            $attributes = [
                'http.request.method' => $method,
                'http.route' => $routeName,
                'http.response.status_code' => $statusCode,
            ];

            $meter = Globals::meterProvider()->getMeter('painel-laravel-http');

            if (self::$requestCounter === null) {
                self::$requestCounter = $meter->createCounter(
                    'laravel_http_server_requests_total',
                    '{request}',
                    'Total de requisições HTTP no Laravel'
                );
            }

            if (self::$requestDuration === null) {
                self::$requestDuration = $meter->createHistogram(
                    'laravel_http_server_request_duration_ms',
                    'ms',
                    'Duração de requisição HTTP no Laravel'
                );
            }

            self::$requestCounter->add(1, $attributes);
            self::$requestDuration->record($durationMs, $attributes);

            $scope->detach();
            $span->end();
        }
    }
}
