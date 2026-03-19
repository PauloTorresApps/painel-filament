<?php

use App\Models\ContractAnalysis;
use App\Models\DocumentAnalysis;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

test('benchmark slo check command fails when breach exists and fail option is enabled', function () {
    config()->set('analysis.slo.judicial.p95_total_ms_max', 1000);

    $user = User::factory()->create();

    DocumentAnalysis::query()->create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-48.2026.4.04.0000',
        'status' => 'completed',
        'current_phase' => DocumentAnalysis::PHASE_COMPLETED,
        'total_documents' => 1,
        'processing_time_ms' => 5000,
    ]);

    $exitCode = Artisan::call('analysis:benchmark-slo-check', [
        '--days' => 30,
        '--format' => 'json',
        '--fail-on-breach' => true,
    ]);

    $output = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(1);
    expect($output['breach_count'])->toBeGreaterThan(0);
});

test('benchmark slo check command succeeds when no slo is configured', function () {
    config()->set('analysis.slo.judicial.p95_total_ms_max', 0);
    config()->set('analysis.slo.judicial.docs_per_min_min', 0);
    config()->set('analysis.slo.contracts.avg_analysis_ms_max', 0);
    config()->set('analysis.slo.contracts.avg_legal_opinion_ms_max', 0);
    config()->set('analysis.slo.contracts.avg_infographic_ms_max', 0);

    $user = User::factory()->create();

    ContractAnalysis::query()->create([
        'user_id' => $user->id,
        'file_name' => 'contrato.pdf',
        'file_path' => 'contracts/contrato.pdf',
        'file_size' => 123,
        'status' => ContractAnalysis::STATUS_COMPLETED,
        'processing_time_ms' => 1000,
    ]);

    $exitCode = Artisan::call('analysis:benchmark-slo-check', [
        '--days' => 30,
        '--fail-on-breach' => true,
    ]);

    expect($exitCode)->toBe(0);
});

test('benchmark slo check sends webhook notification on breach when configured', function () {
    config()->set('analysis.slo.judicial.p95_total_ms_max', 1000);
    config()->set('analysis.slo.alert_webhook_url', 'https://alerts.test/webhook');

    Http::fake();

    $user = User::factory()->create();

    DocumentAnalysis::query()->create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-49.2026.4.04.0000',
        'status' => 'completed',
        'current_phase' => DocumentAnalysis::PHASE_COMPLETED,
        'total_documents' => 1,
        'processing_time_ms' => 5000,
    ]);

    $exitCode = Artisan::call('analysis:benchmark-slo-check', [
        '--days' => 30,
        '--fail-on-breach' => true,
    ]);

    expect($exitCode)->toBe(1);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://alerts.test/webhook'
            && ($request->data()['event'] ?? null) === 'analysis.slo.breach'
            && ($request->data()['breach_count'] ?? 0) > 0;
    });
});
