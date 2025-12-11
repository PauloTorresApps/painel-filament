<?php

use Livewire\Volt\Volt;
use Laravel\Fortify\Features;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EprocController;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('profile.edit');
    Volt::route('settings/password', 'settings.password')->name('user-password.edit');
    Volt::route('settings/appearance', 'settings.appearance')->name('appearance.edit');

    Volt::route('settings/two-factor', 'settings.two-factor')
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                    && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('two-factor.show');
});

Route::get('document_analysis/eproc', [EprocController::class, 'index'])->name('eproc');
Route::get('document_analysis/eproc/debug', [EprocController::class, 'debug'])->name('eproc.debug');
Route::post('document_analysis/eproc/consultar', [EprocController::class, 'consultarProcesso'])->name('eproc.consultar');
Route::post('document_analysis/eproc/visualizar', [EprocController::class, 'visualizarDocumento'])->name('eproc.visualizar');
