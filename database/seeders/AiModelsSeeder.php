<?php

namespace Database\Seeders;

use App\Models\AiModel;
use Illuminate\Database\Seeder;

class AiModelsSeeder extends Seeder
{
    public function run(): void
    {
        $models = [
            [
                'name' => 'Gemini 3.1 Pro (via OpenRouter)',
                'provider' => 'openrouter',
                'model_id' => 'google/gemini-3.1-pro-preview',
                'description' => 'Modelo avançado do Google com janela de contexto expandida e raciocínio profundo.',
                'is_active' => true,
                'supports_reasoning' => true,
                'supports_vision' => true,
            ],
            [
                'name' => 'GPT-5.2 (via OpenRouter)',
                'provider' => 'openrouter',
                'model_id' => 'openai/gpt-5.2',
                'description' => 'Modelo avançado da OpenAI com excelente capacidade de análise de documentos.',
                'is_active' => true,
                'supports_reasoning' => true,
                'supports_vision' => true,
            ],
        ];

        foreach ($models as $model) {
            AiModel::updateOrCreate(
                [
                    'provider' => $model['provider'],
                    'model_id' => $model['model_id'],
                ],
                $model
            );
        }
    }
}
