<?php

use Livewire\Volt\Volt;
use Laravel\Fortify\Features;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EprocController;
use App\Http\Controllers\ContractUploadController;
use App\Http\Controllers\LegalOpinionPdfController;
use App\Http\Controllers\ContractAnalysisPdfController;
use App\Http\Controllers\InfographicController;

Route::redirect('/', '/login')->name('home');

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

        Route::redirect('document_analysis/eproc', '/analises/process-analysis')->name('eproc');
        Route::post('document_analysis/eproc/consultar', [EprocController::class, 'consultarProcesso'])->name('eproc.consultar');
        Route::post('document_analysis/eproc/visualizar', [EprocController::class, 'visualizarDocumento'])->name('eproc.visualizar');

        // Upload de contratos (FilePond chunked upload)
        Route::match(['post', 'patch', 'head'], 'contracts/upload', [ContractUploadController::class, 'upload'])->name('contracts.upload');
        Route::delete('contracts/upload', [ContractUploadController::class, 'delete'])->name('contracts.delete');

        // Download de Parecer Jurídico em PDF
        Route::get('contracts/{id}/legal-opinion/download', [LegalOpinionPdfController::class, 'download'])->name('contracts.legal-opinion.download');
        Route::get('contracts/{id}/legal-opinion/view', [LegalOpinionPdfController::class, 'view'])->name('contracts.legal-opinion.view');

        // Download de Análise Contratual em PDF
        Route::get('contracts/{id}/analysis/download', [ContractAnalysisPdfController::class, 'download'])->name('contracts.analysis.download');
        Route::get('contracts/{id}/analysis/view', [ContractAnalysisPdfController::class, 'view'])->name('contracts.analysis.view');

        // Infográfico Visual Law
        Route::get('contracts/{id}/infographic/view', [InfographicController::class, 'view'])->name('contracts.infographic.view');
        Route::get('contracts/{id}/infographic/download', [InfographicController::class, 'download'])->name('contracts.infographic.download');
        Route::get('contracts/{id}/infographic/storyboard', [InfographicController::class, 'storyboard'])->name('contracts.infographic.storyboard');

});
