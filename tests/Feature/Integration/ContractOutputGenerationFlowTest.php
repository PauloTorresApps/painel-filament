<?php

use App\Jobs\GenerateInfographicJob;
use App\Jobs\GenerateLegalOpinionJob;
use App\Models\AiPrompt;
use App\Models\ContractAnalysis;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('generate legal opinion job completes contract legal opinion flow', function () {
    setupOpenRouterTestingConfig();
    [$system, $model] = ensureContractsSystemAndModel();
    fakeOpenRouterSingleResponse('Parecer juridico final gerado.', 120, 80);

    $prompt = createDefaultContractPrompt(
        $system->id,
        $model->id,
        AiPrompt::TYPE_LEGAL_OPINION,
        'Prompt Parecer',
        'Gere um parecer juridico objetivo.',
        0.4
    );

    $user = User::factory()->create();

    $analysis = ContractAnalysis::query()->create([
        'user_id' => $user->id,
        'file_name' => 'contrato.pdf',
        'file_path' => 'contracts/contrato.pdf',
        'file_size' => 12345,
        'status' => ContractAnalysis::STATUS_COMPLETED,
        'analysis_result' => 'Analise inicial do contrato.',
        'legal_opinion_status' => ContractAnalysis::STATUS_PENDING,
        'infographic_status' => ContractAnalysis::STATUS_PENDING,
    ]);

    (new GenerateLegalOpinionJob($analysis->id))->handle();

    $analysis->refresh();

    expect($analysis->legal_opinion_status)->toBe(ContractAnalysis::STATUS_COMPLETED);
    expect($analysis->legal_opinion_prompt_id)->toBe($prompt->id);
    expect($analysis->legal_opinion_result)->toContain('Parecer juridico final');
    expect($analysis->legal_opinion_processing_time_ms)->toBeInt();
});

test('generate infographic job completes two-phase infographic flow', function () {
    setupOpenRouterTestingConfig();
    [$system, $model] = ensureContractsSystemAndModel();

    fakeOpenRouterSequence([
        '{"estrutura":"storyboard","secoes":[{"titulo":"Resumo","conteudo":"Ponto principal"}]}',
        '<!DOCTYPE html><html><body><h1>Infografico</h1></body></html>',
    ]);

    $storyboardPrompt = createDefaultContractPrompt(
        $system->id,
        $model->id,
        AiPrompt::TYPE_STORYBOARD,
        'Prompt Storyboard',
        'Gere storyboard em JSON.',
        0.3
    );

    $htmlPrompt = createDefaultContractPrompt(
        $system->id,
        $model->id,
        AiPrompt::TYPE_INFOGRAPHIC,
        'Prompt HTML',
        'Converta JSON para HTML.',
        0.3
    );

    $user = User::factory()->create();

    $analysis = ContractAnalysis::query()->create([
        'user_id' => $user->id,
        'file_name' => 'contrato.pdf',
        'file_path' => 'contracts/contrato.pdf',
        'file_size' => 22222,
        'status' => ContractAnalysis::STATUS_COMPLETED,
        'analysis_result' => 'Analise inicial do contrato.',
        'legal_opinion_status' => ContractAnalysis::STATUS_COMPLETED,
        'legal_opinion_result' => 'Parecer juridico concluido.',
        'infographic_status' => ContractAnalysis::STATUS_PENDING,
    ]);

    (new GenerateInfographicJob($analysis->id))->handle();

    $analysis->refresh();

    expect($analysis->infographic_status)->toBe(ContractAnalysis::STATUS_COMPLETED);
    expect($analysis->infographic_storyboard_prompt_id)->toBe($storyboardPrompt->id);
    expect($analysis->infographic_html_prompt_id)->toBe($htmlPrompt->id);
    expect($analysis->infographic_storyboard_json)->toContain('"estrutura":"storyboard"');
    expect($analysis->infographic_html_result)->toContain('<html>');
    expect($analysis->infographic_progress_percent)->toBe(100);
});

test('generate legal opinion job exits early when legal opinion is cancelled', function () {
    setupOpenRouterTestingConfig();

    Http::fake();

    $user = User::factory()->create();

    $analysis = ContractAnalysis::query()->create([
        'user_id' => $user->id,
        'file_name' => 'contrato-cancelado.pdf',
        'file_path' => 'contracts/contrato-cancelado.pdf',
        'file_size' => 999,
        'status' => ContractAnalysis::STATUS_COMPLETED,
        'analysis_result' => 'Analise base',
        'legal_opinion_status' => ContractAnalysis::STATUS_CANCELLED,
        'infographic_status' => ContractAnalysis::STATUS_PENDING,
    ]);

    (new GenerateLegalOpinionJob($analysis->id))->handle();

    $analysis->refresh();

    expect($analysis->legal_opinion_status)->toBe(ContractAnalysis::STATUS_CANCELLED);
    expect($analysis->legal_opinion_result)->toBeNull();
    Http::assertNothingSent();
});

test('generate infographic job marks analysis as failed when storyboard json is invalid', function () {
    setupOpenRouterTestingConfig();
    [$system, $model] = ensureContractsSystemAndModel();

    fakeOpenRouterSingleResponse('resposta sem json valido');

    createDefaultContractPrompt(
        $system->id,
        $model->id,
        AiPrompt::TYPE_STORYBOARD,
        'Prompt Storyboard',
        'Gere storyboard em JSON.',
        0.3
    );

    createDefaultContractPrompt(
        $system->id,
        $model->id,
        AiPrompt::TYPE_INFOGRAPHIC,
        'Prompt HTML',
        'Converta JSON para HTML.',
        0.3
    );

    $user = User::factory()->create();

    $analysis = ContractAnalysis::query()->create([
        'user_id' => $user->id,
        'file_name' => 'contrato.pdf',
        'file_path' => 'contracts/contrato.pdf',
        'file_size' => 22222,
        'status' => ContractAnalysis::STATUS_COMPLETED,
        'analysis_result' => 'Analise inicial do contrato.',
        'legal_opinion_status' => ContractAnalysis::STATUS_COMPLETED,
        'legal_opinion_result' => 'Parecer juridico concluido.',
        'infographic_status' => ContractAnalysis::STATUS_PENDING,
    ]);

    (new GenerateInfographicJob($analysis->id))->handle();

    $analysis->refresh();

    expect($analysis->infographic_status)->toBe(ContractAnalysis::STATUS_FAILED);
    expect($analysis->infographic_error)->toContain('JSON inválido');
});

test('generate legal opinion job marks analysis as failed when provider returns 500', function () {
    setupOpenRouterTestingConfig();
    [$system, $model] = ensureContractsSystemAndModel();

    Http::fake([
        'https://openrouter.test/api/v1/chat/completions' => Http::response([
            'error' => ['message' => 'internal server error'],
        ], 500),
    ]);

    createDefaultContractPrompt(
        $system->id,
        $model->id,
        AiPrompt::TYPE_LEGAL_OPINION,
        'Prompt Parecer',
        'Gere um parecer juridico objetivo.',
        0.4
    );

    $user = User::factory()->create();

    $analysis = ContractAnalysis::query()->create([
        'user_id' => $user->id,
        'file_name' => 'contrato-erro.pdf',
        'file_path' => 'contracts/contrato-erro.pdf',
        'file_size' => 4444,
        'status' => ContractAnalysis::STATUS_COMPLETED,
        'analysis_result' => 'Analise inicial do contrato.',
        'legal_opinion_status' => ContractAnalysis::STATUS_PENDING,
        'infographic_status' => ContractAnalysis::STATUS_PENDING,
    ]);

    (new GenerateLegalOpinionJob($analysis->id))->handle();

    $analysis->refresh();

    expect($analysis->legal_opinion_status)->toBe(ContractAnalysis::STATUS_FAILED);
    expect($analysis->legal_opinion_error)->toContain('Erro temporário no servidor OpenRouter');
});

test('generate infographic job exits early when legal opinion is not completed', function () {
    setupOpenRouterTestingConfig();

    Http::fake();

    $user = User::factory()->create();

    $analysis = ContractAnalysis::query()->create([
        'user_id' => $user->id,
        'file_name' => 'contrato-sem-parecer.pdf',
        'file_path' => 'contracts/contrato-sem-parecer.pdf',
        'file_size' => 3333,
        'status' => ContractAnalysis::STATUS_COMPLETED,
        'analysis_result' => 'Analise inicial do contrato.',
        'legal_opinion_status' => ContractAnalysis::STATUS_PENDING,
        'infographic_status' => ContractAnalysis::STATUS_PENDING,
    ]);

    (new GenerateInfographicJob($analysis->id))->handle();

    $analysis->refresh();

    expect($analysis->infographic_status)->toBe(ContractAnalysis::STATUS_PENDING);
    expect($analysis->infographic_storyboard_json)->toBeNull();
    Http::assertNothingSent();
});
