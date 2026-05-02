<?php

use App\Models\AiModel;
use App\Models\AiPrompt;
use App\Models\System;

it('imports required prompts from external json', function () {
    $system = System::create([
        'name' => 'Sistema Import Test',
        'description' => 'Sistema de teste para importacao',
        'is_active' => true,
    ]);

    $model = AiModel::create([
        'name' => 'Modelo Import Test',
        'provider' => 'openrouter',
        'model_id' => 'openai/gpt-4o-mini-import-test',
        'description' => 'Modelo de teste para importacao',
        'is_active' => true,
        'supports_reasoning' => true,
        'supports_vision' => true,
    ]);

    $payload = [];

    foreach (AiPrompt::REQUIRED_JUDICIAL_PROMPT_TYPES as $type) {
        $payload[$type] = [
            'title' => 'Prompt ' . $type,
            'content' => 'Conteudo de teste para ' . $type,
            'ai_provider' => 'openrouter',
            'ai_model_id' => $model->id,
            'deep_thinking_enabled' => true,
            'analysis_strategy' => 'evolutionary',
            'temperature' => 0.3,
        ];
    }

    $jsonPath = sys_get_temp_dir() . '/required-prompts-import-test-' . uniqid('', true) . '.json';
    file_put_contents($jsonPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $this->artisan('analysis:import-required-prompts', [
        'jsonPath' => $jsonPath,
        '--system' => $system->id,
    ])->assertExitCode(0);

    $count = AiPrompt::query()
        ->where('system_id', $system->id)
        ->whereIn('prompt_type', AiPrompt::REQUIRED_JUDICIAL_PROMPT_TYPES)
        ->where('is_active', true)
        ->where('is_default', true)
        ->count();

    expect($count)->toBe(count(AiPrompt::REQUIRED_JUDICIAL_PROMPT_TYPES));

    @unlink($jsonPath);
});
