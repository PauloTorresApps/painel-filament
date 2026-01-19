<?php

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LoginResponse as FilamentLoginResponseContract;
use Laravel\Fortify\Contracts\LoginResponse as FortifyLoginResponseContract;
use Illuminate\Http\RedirectResponse;

class LoginResponse implements FilamentLoginResponseContract, FortifyLoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        $user = auth()->user();

        if (!$user) {
            return redirect('/');
        }

        // Admin e Manager vão para o painel admin
        if ($user->hasRole(['Admin', 'Manager'])) {
            return redirect('/admin');
        }

        // Todos os outros usuários vão para o painel de análises
        return redirect('/analises');
    }
}
