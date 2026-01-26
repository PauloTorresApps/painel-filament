<?php

namespace App\Filament\Resources\AiPrompts\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Hidden;

class AiPromptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // system_id fixo para EPROC (análise de processos)
                Hidden::make('system_id')
                    ->default(1),

                TextInput::make('title')
                    ->label('Título')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Dê um nome descritivo para identificar este prompt'),

                Select::make('ai_provider')
                    ->label('Provedor de IA')
                    ->options(\App\Models\AiPrompt::getAvailableProviders())
                    ->default('gemini')
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set) {
                        // Se mudar para um provider diferente de deepseek, desativa o deep thinking
                        if ($state !== 'deepseek') {
                            $set('deep_thinking_enabled', false);
                        } else {
                            // Se mudar para deepseek, ativa por padrão
                            $set('deep_thinking_enabled', true);
                        }
                    })
                    ->helperText('Selecione qual inteligência artificial será utilizada para processar este prompt'),

                Toggle::make('deep_thinking_enabled')
                    ->label('Modo de Pensamento Profundo (DeepSeek)')
                    ->default(true)
                    ->helperText('Ativa o modo de reasoning da DeepSeek para análises mais detalhadas. Recomendado para tarefas complexas.')
                    ->visible(fn ($get) => $get('ai_provider') === 'deepseek')
                    ->dehydrated(fn ($get) => $get('ai_provider') === 'deepseek'),

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
