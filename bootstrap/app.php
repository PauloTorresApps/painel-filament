<?php

use App\Http\Middleware\OtelHttpTelemetryMiddleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(OtelHttpTelemetryMiddleware::class);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // DESABILITADO: Cleanup automático de análises travadas
        // Análises agora podem rodar indefinidamente (sem timeout)
        // Comando manual disponível: php artisan analyses:cleanup-stuck --timeout=XXX

        // $schedule->command('analyses:cleanup-stuck --timeout=30 --no-interaction')
        //     ->hourly()
        //     ->withoutOverlapping()
        //     ->runInBackground();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
