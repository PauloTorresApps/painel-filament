<?php

use App\Jobs\GenerateInfographicJob;
use App\Jobs\GenerateLegalOpinionJob;
use App\Models\AiModel;
use App\Models\AiPrompt;
use App\Models\ContractAnalysis;
use App\Models\System;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function makeOpenRouterSetup(): array
{
    config()->set('services.openrouter.api_key', 'test-api-key');
    config()->set('services.openrouter.api_url', 'https://openrouter.test/api/v1');
    config()->set('services.openrouter.timeout', 30);

    $system = System::query()->firstOrCreate(
        ['name' => 'Contratos'],
        ['description' => 'Sistema de contratos', 'is_active' => true]
    );

    $model = AiModel::query()->create([
        'name' => 'Test Model',
        'provider' => 'openrouter',
        'model_id' => 'openrouter/test-model',
        'description' => 'Model for tests',
        'is_active' => true,
        'supports_reasoning' => false,
        'supports_vision' => false,
    ]);

    return [$system, $model];
}

test('generate legal opinion job completes contract legal opinion flow', function () {
    [$system, $model] = makeOpenRouterSetup();

    Http::fake([
        'https://openrouter.test/api/v1/chat/completions' => Http::response([
            'id' => 'gen-1',
            'model' => 'openrouter/test-model',
            'choices' => [
                [
                    'message' => [
                        'content' => 'Parecer juridico final gerado.',
                    ],
                ],
            ],
            'usage' => [
                'prompt_tokens' => 120,
                'completion_tokens' => 80,
            ],
        ], 200),
    ]);

    $prompt = AiPrompt::query()->create([
        'system_id' => $system->id,
        'prompt_type' => AiPrompt::TYPE_LEGAL_OPINION,
        'title' => 'Prompt Parecer',
        'content' => 'Gere um parecer juridico objetivo.',
        'ai_provider' => 'openrouter',
        'ai_model_id' => $model->id,
        'deep_thinking_enabled' => false,
        'analysis_strategy' => 'evolutionary',
        'temperature' => 0.4,
        'is_active' => true,
        'is_default' => true,
    ]);

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
    [$system, $model] = makeOpenRouterSetup();

    Http::fake([
        'https://openrouter.test/api/v1/chat/completions' => Http::sequence()
            ->push([
                'id' => 'storyboard-1',
                'model' => 'openrouter/test-model',
                'choices' => [
                    [
                        'message' => [
                            'content' => '{"estrutura":"storyboard","secoes":[{"titulo":"Resumo","conteudo":"Ponto principal"}]}'
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 70,
                ],
            ], 200)
            ->push([
                'id' => 'html-1',
                'model' => 'openrouter/test-model',
                'choices' => [
                    [
                        'message' => [
                            'content' => '<!DOCTYPE html><html><body><h1>Infografico</h1></body></html>',
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 80,
                    'completion_tokens' => 60,
                ],
            ], 200),
    ]);

    $storyboardPrompt = AiPrompt::query()->create([
        'system_id' => $system->id,
        'prompt_type' => AiPrompt::TYPE_STORYBOARD,
        'title' => 'Prompt Storyboard',
        'content' => 'Gere storyboard em JSON.',
        'ai_provider' => 'openrouter',
        'ai_model_id' => $model->id,
        'deep_thinking_enabled' => false,
        'analysis_strategy' => 'evolutionary',
        'temperature' => 0.3,
        'is_active' => true,
        'is_default' => true,
    ]);

    $htmlPrompt = AiPrompt::query()->create([
        'system_id' => $system->id,
        'prompt_type' => AiPrompt::TYPE_INFOGRAPHIC,
        'title' => 'Prompt HTML',
        'content' => 'Converta JSON para HTML.',
        'ai_provider' => 'openrouter',
        'ai_model_id' => $model->id,
        'deep_thinking_enabled' => false,
        'analysis_strategy' => 'evolutionary',
        'temperature' => 0.3,
        'is_active' => true,
        'is_default' => true,
    ]);

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
