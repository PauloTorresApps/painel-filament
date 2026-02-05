<?php

namespace App\Filament\Resources\AiPrompts\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Hidden;
use Illuminate\Database\Eloquent\Builder;

class AiPromptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('system_id')
                    ->label('Sistema Judicial')
                    ->relationship(
                        'system',
                        'name',
                        fn (Builder $query) => $query
                            ->where('is_active', true)
                            ->where('name', '!=', 'Contratos') // Contratos tem resource próprio
                            ->orderBy('name')
                    )
                    ->required()
                    ->searchable()
                    ->helperText('Selecione o sistema judicial (EPROC, PJE, etc.) ao qual este prompt se aplica'),

                TextInput::make('title')
                    ->label('Título')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Dê um nome descritivo para identificar este prompt'),

                Select::make('prompt_type')
                    ->label('Finalidade do Prompt')
                    ->options(\App\Models\AiPrompt::getJudicialPromptTypes())
                    ->required()
                    ->native(false)
                    ->helperText('Análise de Documentos: usado na fase MAP para analisar cada documento individualmente. Parecer Final: usado na fase REDUCE para gerar a consolidação final.'),

                Select::make('ai_model_id')
                    ->label('Modelo de IA')
                    ->options(function (): array {
                        $options = [];
                        $providers = \App\Models\AiModel::getAvailableProviders();

                        foreach ($providers as $providerKey => $providerName) {
                            $models = \App\Models\AiModel::query()
                                ->where('is_active', true)
                                ->where('provider', $providerKey)
                                ->orderBy('name')
                                ->get();

                            if ($models->isNotEmpty()) {
                                $options[$providerName] = $models->mapWithKeys(
                                    fn (\App\Models\AiModel $model): array => [
                                        $model->id => "{$model->name} ({$model->model_id})"
                                    ]
                                )->toArray();
                            }
                        }

                        return $options;
                    })
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set) {
                        if ($state) {
                            $model = \App\Models\AiModel::find($state);
                            if ($model) {
                                // Atualiza o deep_thinking baseado no provider e modelo
                                $supportsReasoning = self::modelSupportsReasoning($model->provider, $model->model_id);
                                $set('deep_thinking_enabled', $supportsReasoning);
                            }
                        }
                    })
                    ->helperText('Selecione qual modelo de IA será utilizado para processar este prompt'),

                Toggle::make('deep_thinking_enabled')
                    ->label('Modo de Pensamento Profundo')
                    ->default(true)
                    ->helperText('Ativa o modo de reasoning para análises mais detalhadas. Disponível para DeepSeek e modelos OpenRouter com suporte a reasoning (Claude, o1, DeepSeek R1, etc.).')
                    ->visible(function ($get) {
                        $modelId = $get('ai_model_id');
                        if (!$modelId) return false;
                        $model = \App\Models\AiModel::find($modelId);
                        return $model && self::modelSupportsReasoning($model->provider, $model->model_id);
                    })
                    ->dehydrated(function ($get) {
                        $modelId = $get('ai_model_id');
                        if (!$modelId) return false;
                        $model = \App\Models\AiModel::find($modelId);
                        return $model && self::modelSupportsReasoning($model->provider, $model->model_id);
                    }),

                Textarea::make('content')
                    ->label('Conteúdo do Prompt')
                    ->required()
                    ->rows(8)
                    ->maxLength(10000)
                    ->helperText('Digite o texto do prompt que será enviado para a IA. HTML e scripts serão automaticamente removidos por segurança.')
                    ->columnSpanFull(),

                Toggle::make('is_active')
                    ->label('Ativo')
                    ->default(true)
                    ->reactive()
                    ->helperText('Desative para manter o prompt salvo mas não utilizá-lo'),

                Toggle::make('is_default')
                    ->label('Prompt Padrão')
                    ->default(false)
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set) {
                        // Quando o prompt for definido como padrão, deve estar ativo
                        if ($state === true) {
                            $set('is_active', true);
                        }
                    })
                    ->helperText('Define este prompt como padrão para o sistema e finalidade selecionados. Pode haver um prompt padrão para cada finalidade (Análise de Documentos + Parecer Final).'),
            ]);
    }

    /**
     * Verifica se o modelo suporta reasoning/deep thinking
     */
    private static function modelSupportsReasoning(string $provider, string $modelId): bool
    {
        // DeepSeek sempre suporta
        if ($provider === 'deepseek') {
            return true;
        }

        // OpenRouter - modelos específicos que suportam reasoning
        if ($provider === 'openrouter') {
            $reasoningModels = [
                // DeepSeek
                'deepseek/deepseek-r1',
                'deepseek/deepseek-reasoner',
                // OpenAI
                'openai/o1',
                'openai/o1-mini',
                'openai/o1-preview',
                'openai/o3-mini',
                // Google
                'google/gemini-2.0-flash-thinking-exp',
                'google/gemini-2.5-flash-preview',
                'google/gemini-2.5-pro-preview',
                // Anthropic
                'anthropic/claude-sonnet-4',
                'anthropic/claude-3.7-sonnet',
                // xAI Grok (suportam reasoning via parâmetro)
                'x-ai/grok-3',
                'x-ai/grok-3-fast',
                'x-ai/grok-3-mini',
                'x-ai/grok-3-mini-fast',
                'x-ai/grok-4.1',
                'x-ai/grok-4.1-fast',
                'x-ai/grok-4.1-mini',
                'x-ai/grok-4.1-mini-fast',
            ];

            foreach ($reasoningModels as $reasoningModel) {
                if (str_contains($modelId, $reasoningModel) || $modelId === $reasoningModel) {
                    return true;
                }
            }
        }

        return false;
    }
}
