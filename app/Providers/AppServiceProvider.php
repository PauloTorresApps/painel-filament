<?php

namespace App\Providers;

use App\Http\Responses\LoginResponse;
use Filament\Facades\Filament;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as FilamentLoginResponseContract;
use Laravel\Fortify\Contracts\LoginResponse as FortifyLoginResponseContract;
use Illuminate\Support\Facades\Gate;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Registra LoginResponse customizado para redirecionamento por role (Filament e Fortify)
        $this->app->singleton(FilamentLoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(FortifyLoginResponseContract::class, LoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::after(function ($user, $ability): ?bool {
            return $user->hasRole('Admin') ? true : null;
        });
    }
}
