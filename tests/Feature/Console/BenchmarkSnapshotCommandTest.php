<?php

use App\Models\AnalysisBenchmarkSnapshot;
use App\Models\DocumentAnalysis;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

test('benchmark snapshot command stores snapshot with metrics payload', function () {
    $user = User::factory()->create();

    DocumentAnalysis::query()->create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-47.2026.4.04.0000',
        'status' => 'completed',
        'current_phase' => DocumentAnalysis::PHASE_COMPLETED,
        'total_documents' => 1,
        'processing_time_ms' => 1000,
    ]);

    $exitCode = Artisan::call('analysis:benchmark-snapshot', [
        '--days' => 30,
    ]);

    expect($exitCode)->toBe(0);

    $snapshot = AnalysisBenchmarkSnapshot::query()->latest('id')->first();

    expect($snapshot)->not->toBeNull();
    expect($snapshot->window_days)->toBe(30);
    expect($snapshot->metrics)->toBeArray();
    expect($snapshot->metrics['judicial']['completed_analyses'])->toBe(1);
    expect($snapshot->slo_breaches)->toBeNull();
});
