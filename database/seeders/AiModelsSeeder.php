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

            // OpenRouter - Modelos de pensamento profundo e análise de documentos
            [
                'name' => 'Claude Sonnet 4 (via OpenRouter)',
                'provider' => 'openrouter',
                'model_id' => 'anthropic/claude-sonnet-4',
                'description' => 'Modelo mais recente da Anthropic. Excelente para análise jurídica com raciocínio avançado.',
                'is_active' => true,
            ],
            [
                'name' => 'Claude 3.5 Sonnet (via OpenRouter)',
                'provider' => 'openrouter',
                'model_id' => 'anthropic/claude-3.5-sonnet',
                'description' => 'Modelo avançado da Anthropic com excelente compreensão de documentos longos.',
                'is_active' => true,
            ],
            [
                'name' => 'Claude 3 Opus (via OpenRouter)',
                'provider' => 'openrouter',
                'model_id' => 'anthropic/claude-3-opus',
                'description' => 'Modelo premium da Anthropic para análises complexas e detalhadas.',
                'is_active' => true,
            ],
            [
                'name' => 'DeepSeek R1 (via OpenRouter)',
                'provider' => 'openrouter',
                'model_id' => 'deepseek/deepseek-r1',
                'description' => 'Modelo de pensamento profundo com excelente custo-benefício. Reasoning avançado.',
                'is_active' => true,
            ],
            [
                'name' => 'GPT-4o (via OpenRouter)',
                'provider' => 'openrouter',
                'model_id' => 'openai/gpt-4o',
                'description' => 'Modelo multimodal da OpenAI. Suporta análise de texto e imagens.',
                'is_active' => true,
            ],
            [
                'name' => 'o1 (via OpenRouter)',
                'provider' => 'openrouter',
                'model_id' => 'openai/o1',
                'description' => 'Modelo de raciocínio avançado da OpenAI. Ideal para análises que requerem pensamento profundo.',
                'is_active' => true,
            ],
            [
                'name' => 'o3-mini (via OpenRouter)',
                'provider' => 'openrouter',
                'model_id' => 'openai/o3-mini',
                'description' => 'Versão compacta do o3 com raciocínio avançado e custo otimizado.',
                'is_active' => true,
            ],
            [
                'name' => 'Gemini 2.0 Flash Thinking (via OpenRouter)',
                'provider' => 'openrouter',
                'model_id' => 'google/gemini-2.0-flash-thinking-exp',
                'description' => 'Modelo experimental do Google com capacidade de pensamento profundo.',
                'is_active' => true,
            ],
            [
                'name' => 'Gemini 2.5 Pro (via OpenRouter)',
                'provider' => 'openrouter',
                'model_id' => 'google/gemini-2.5-pro-preview',
                'description' => 'Versão mais recente do Gemini Pro com janela de contexto expandida.',
                'is_active' => true,
            ],
            [
                'name' => 'Llama 3.3 70B (via OpenRouter)',
                'provider' => 'openrouter',
                'model_id' => 'meta-llama/llama-3.3-70b-instruct',
                'description' => 'Modelo open-source da Meta com 70B parâmetros. Bom custo-benefício.',
                'is_active' => true,
            ],
            [
                'name' => 'Grok 4.1 Fast (via OpenRouter)',
                'provider' => 'openrouter',
                'model_id' => 'x-ai/grok-4.1-fast',
                'description' => 'Modelo de raciocínio da xAI. Rápido com suporte a pensamento profundo.',
                'is_active' => true,
            ],
            [
                'name' => 'Grok 4.1 (via OpenRouter)',
                'provider' => 'openrouter',
                'model_id' => 'x-ai/grok-4.1',
                'description' => 'Modelo premium da xAI com raciocínio avançado e análise de documentos.',
                'is_active' => true,
            ],
            [
                'name' => 'Grok 3 Fast (via OpenRouter)',
                'provider' => 'openrouter',
                'model_id' => 'x-ai/grok-3-fast',
                'description' => 'Modelo rápido da xAI com bom custo-benefício para análises.',
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
