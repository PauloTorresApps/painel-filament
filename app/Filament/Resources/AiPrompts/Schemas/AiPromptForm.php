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
                                // Atualiza o deep_thinking baseado no provider
                                if ($model->provider === 'deepseek') {
                                    $set('deep_thinking_enabled', true);
                                } else {
                                    $set('deep_thinking_enabled', false);
                                }
                            }
                        }
                    })
                    ->helperText('Selecione qual modelo de IA será utilizado para processar este prompt'),

                Toggle::make('deep_thinking_enabled')
                    ->label('Modo de Pensamento Profundo (DeepSeek)')
                    ->default(true)
                    ->helperText('Ativa o modo de reasoning da DeepSeek para análises mais detalhadas. Recomendado para tarefas complexas.')
                    ->visible(function ($get) {
                        $modelId = $get('ai_model_id');
                        if (!$modelId) return false;
                        $model = \App\Models\AiModel::find($modelId);
                        return $model && $model->provider === 'deepseek';
                    })
                    ->dehydrated(function ($get) {
                        $modelId = $get('ai_model_id');
                        if (!$modelId) return false;
                        $model = \App\Models\AiModel::find($modelId);
                        return $model && $model->provider === 'deepseek';
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
                    ->helperText('Define este prompt como padrão para o sistema selecionado. Só pode haver um prompt padrão por sistema.'),
            ]);
    }
}
