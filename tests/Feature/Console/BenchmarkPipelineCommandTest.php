<?php

use App\Models\ContractAnalysis;
use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

test('analysis benchmark command returns expected json metrics', function () {
    $user = User::factory()->create();

    $judicial = DocumentAnalysis::query()->create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-46.2026.4.04.0000',
        'status' => 'completed',
        'current_phase' => DocumentAnalysis::PHASE_COMPLETED,
        'total_documents' => 3,
        'processing_time_ms' => 120000,
    ]);

    DocumentMicroAnalysis::query()->create([
        'document_analysis_id' => $judicial->id,
        'document_index' => 1,
        'descricao' => 'Map 1',
        'status' => 'completed',
        'reduce_level' => 0,
        'token_count' => 100,
        'processing_time_ms' => 1000,
        'micro_analysis' => 'ok',
    ]);

    DocumentMicroAnalysis::query()->create([
        'document_analysis_id' => $judicial->id,
        'document_index' => 2,
        'descricao' => 'Map 2',
        'status' => 'completed',
        'reduce_level' => 0,
        'token_count' => 200,
        'processing_time_ms' => 2000,
        'micro_analysis' => 'ok',
    ]);

    DocumentMicroAnalysis::query()->create([
        'document_analysis_id' => $judicial->id,
        'document_index' => 1,
        'descricao' => 'Reduce 1',
        'status' => 'completed',
        'reduce_level' => 1,
        'token_count' => 300,
        'processing_time_ms' => 3000,
        'micro_analysis' => 'ok',
    ]);

    ContractAnalysis::query()->create([
        'user_id' => $user->id,
        'file_name' => 'contrato.pdf',
        'file_path' => 'contracts/contrato.pdf',
        'file_size' => 123,
        'status' => ContractAnalysis::STATUS_COMPLETED,
        'processing_time_ms' => 10000,
        'legal_opinion_processing_time_ms' => 5000,
        'infographic_processing_time_ms' => 7000,
        'analysis_ai_metadata' => ['total_tokens' => 400],
        'legal_opinion_ai_metadata' => ['total_tokens' => 500],
        'infographic_ai_metadata' => ['totals' => ['total_tokens' => 600]],
    ]);

    $exitCode = Artisan::call('analysis:benchmark-pipeline', [
        '--days' => 30,
        '--format' => 'json',
    ]);

    $output = trim(Artisan::output());
    $data = json_decode($output, true);

    expect($exitCode)->toBe(0);
    expect($data)->toBeArray();

    expect($data['window_days'])->toBe(30);

    expect($data['judicial']['completed_analyses'])->toBe(1);
    expect($data['judicial']['map_micro_completed'])->toBe(2);
    expect($data['judicial']['reduce_micro_completed'])->toBe(1);
    expect($data['judicial']['map_tokens_total'])->toBe(300);
    expect($data['judicial']['reduce_tokens_total'])->toBe(300);
    expect($data['judicial']['tokens_total'])->toBe(600);

    expect($data['contracts']['completed_analyses'])->toBe(1);
    expect($data['contracts']['analysis_tokens_total'])->toBe(400);
    expect($data['contracts']['legal_opinion_tokens_total'])->toBe(500);
    expect($data['contracts']['infographic_tokens_total'])->toBe(600);
    expect($data['contracts']['tokens_total'])->toBe(1500);
});
