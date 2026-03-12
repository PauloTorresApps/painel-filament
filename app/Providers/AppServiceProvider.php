<?php

namespace App\Providers;

use App\Services\OtelMetricsService;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\LogoutResponse;
use App\Traits\WithOtelTracing;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as FilamentLoginResponseContract;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Contracts\LoginResponse as FortifyLoginResponseContract;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    use WithOtelTracing;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Registra LoginResponse customizado para redirecionamento por role (Filament e Fortify)
        $this->app->singleton(FilamentLoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(FortifyLoginResponseContract::class, LoginResponse::class);

        // Registra LogoutResponse customizado para sempre redirecionar para /login
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);

        // Registra servico central de metricas OTel para reuso em middleware e listeners
        $this->app->singleton(OtelMetricsService::class, fn () => new OtelMetricsService());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::after(function ($user, $ability): ?bool {
            return $user->hasRole('Admin') ? true : null;
        });

        DB::listen(function (QueryExecuted $query): void {
            $thresholdMs = (float) config('analysis.telemetry.db_min_duration_ms', 10);

            if ($query->time < $thresholdMs) {
                return;
            }

            $metrics = app(OtelMetricsService::class);
            $metrics->recordDbQuery($query->time, $query->connectionName);

            [$span, $scope] = $this->startSpan('painel-laravel-db', 'db.query', [
                'db.system' => 'postgresql',
                'db.connection' => $query->connectionName,
                'db.statement' => $this->sanitizeSql($query->sql),
                'db.duration_ms' => $query->time,
            ]);

            $this->finishSpanSuccess($span);
            $this->detachScope($scope);
        });
    }

    private function sanitizeSql(string $sql): string
    {
        $singleLine = preg_replace('/\s+/', ' ', trim($sql)) ?: '';

        return mb_substr($singleLine, 0, 500);
    }
}
