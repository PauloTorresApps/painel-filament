<?php

namespace Database\Seeders;

use App\Models\AiModel;
use Illuminate\Database\Seeder;

class AiModelsSeeder extends Seeder
{
    public function run(): void
    {
        $models = [
            // Google Gemini
            [
                'name' => 'Gemini 2.5 Flash Lite',
                'provider' => 'gemini',
                'model_id' => 'gemini-2.5-flash-lite',
                'description' => 'Modelo rápido e econômico do Google, ideal para análises de alto volume.',
                'is_active' => true,
            ],
            [
                'name' => 'Gemini 2.5 Flash',
                'provider' => 'gemini',
                'model_id' => 'gemini-2.5-flash',
                'description' => 'Modelo balanceado do Google com boa velocidade e qualidade.',
                'is_active' => true,
            ],
            [
                'name' => 'Gemini 2.5 Pro',
                'provider' => 'gemini',
                'model_id' => 'gemini-2.5-pro',
                'description' => 'Modelo mais avançado do Google, maior qualidade de resposta.',
                'is_active' => true,
            ],
            [
                'name' => 'Gemini 1.5 Pro',
                'provider' => 'gemini',
                'model_id' => 'gemini-1.5-pro',
                'description' => 'Modelo anterior do Google com grande janela de contexto.',
                'is_active' => true,
            ],

            // OpenAI
            [
                'name' => 'GPT-4o',
                'provider' => 'openai',
                'model_id' => 'gpt-4o',
                'description' => 'Modelo multimodal mais avançado da OpenAI.',
                'is_active' => true,
            ],
            [
                'name' => 'GPT-4o Mini',
                'provider' => 'openai',
                'model_id' => 'gpt-4o-mini',
                'description' => 'Versão mais rápida e econômica do GPT-4o.',
                'is_active' => true,
            ],
            [
                'name' => 'GPT-4 Turbo',
                'provider' => 'openai',
                'model_id' => 'gpt-4-turbo',
                'description' => 'Modelo GPT-4 otimizado para velocidade.',
                'is_active' => true,
            ],
            [
                'name' => 'o1',
                'provider' => 'openai',
                'model_id' => 'o1',
                'description' => 'Modelo com raciocínio avançado da OpenAI.',
                'is_active' => true,
            ],
            [
                'name' => 'o1 Mini',
                'provider' => 'openai',
                'model_id' => 'o1-mini',
                'description' => 'Versão compacta do modelo o1 com raciocínio.',
                'is_active' => true,
            ],

            // DeepSeek
            [
                'name' => 'DeepSeek Chat',
                'provider' => 'deepseek',
                'model_id' => 'deepseek-chat',
                'description' => 'Modelo principal do DeepSeek para chat e análise.',
                'is_active' => true,
            ],
            [
                'name' => 'DeepSeek Reasoner',
                'provider' => 'deepseek',
                'model_id' => 'deepseek-reasoner',
                'description' => 'Modelo com capacidade de raciocínio profundo (Deep Thinking).',
                'is_active' => true,
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
