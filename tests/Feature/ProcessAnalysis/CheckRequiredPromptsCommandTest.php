<?php

use App\Models\AiModel;
use App\Models\AiPrompt;
use App\Models\System;

it('fails when required prompts are missing', function () {
    $system = System::create([
        'name' => 'Sistema Teste A',
        'description' => 'Sistema de teste',
        'is_active' => true,
    ]);

    $this->artisan('analysis:check-required-prompts', ['--system' => $system->id])
        ->assertExitCode(1);
});

it('succeeds when all required prompts are configured', function () {
    $system = System::create([
        'name' => 'Sistema Teste B',
        'description' => 'Sistema de teste',
        'is_active' => true,
    ]);

    $model = AiModel::create([
        'name' => 'Modelo Teste',
        'provider' => 'openrouter',
        'model_id' => 'openai/gpt-4o-mini-test',
        'description' => 'Modelo de teste',
        'is_active' => true,
        'supports_reasoning' => true,
        'supports_vision' => true,
    ]);

    foreach (AiPrompt::REQUIRED_JUDICIAL_PROMPT_TYPES as $promptType) {
        AiPrompt::create([
            'system_id' => $system->id,
            'prompt_type' => $promptType,
            'title' => 'Prompt ' . $promptType,
            'content' => 'Conteudo para ' . $promptType,
            'ai_provider' => 'openrouter',
            'ai_model_id' => $model->id,
            'deep_thinking_enabled' => true,
            'analysis_strategy' => 'evolutionary',
            'temperature' => 0.3,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    $this->artisan('analysis:check-required-prompts', ['--system' => $system->id])
        ->assertExitCode(0);
});
