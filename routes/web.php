<?php

use Livewire\Volt\Volt;
use Laravel\Fortify\Features;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EprocController;
use App\Http\Controllers\JudicialUserController;
use App\Http\Controllers\ContractUploadController;

Route::redirect('/', '/admin');

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

        Route::redirect('document_analysis/eproc', '/admin/process-analysis')->name('eproc');
        Route::get('document_analysis/eproc/debug', [EprocController::class, 'debug'])->name('eproc.debug');
        Route::post('document_analysis/eproc/consultar', [EprocController::class, 'consultarProcesso'])->name('eproc.consultar');
        Route::post('document_analysis/eproc/visualizar', [EprocController::class, 'visualizarDocumento'])->name('eproc.visualizar');

        // Upload de contratos (FilePond chunked upload)
        Route::post('contracts/upload', [ContractUploadController::class, 'upload'])->name('contracts.upload');
        Route::delete('contracts/upload', [ContractUploadController::class, 'delete'])->name('contracts.delete');

        Route::resource('judicial-users', JudicialUserController::class);
});
