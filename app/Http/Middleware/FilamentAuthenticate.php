<?php

namespace App\Http\Middleware;

use Filament\Http\Middleware\Authenticate as FilamentAuthMiddleware;

class FilamentAuthenticate extends FilamentAuthMiddleware
{
    protected function redirectTo($request): ?string
    {
        return '/login';
    }
}
