<?php

namespace App\Filament\Resources\AiPrompts\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

class AiPromptForm
{
    public static function configure(Schema $schema): Schema
    {
        $user = Auth::user();
        $isAdminOrManager = $user->hasRole(['Admin', 'Manager']);

        return $schema
            ->components([
                // Se for Admin/Manager, mostra o select de usuários
                // Se não for, usa um campo hidden com o ID do usuário logado
                $isAdminOrManager
                    ? Select::make('user_id')
                        ->label('Usuário')
                        ->relationship(
                            'user',
                            'name',
                            fn (Builder $query) => $query->orderBy('name')
                        )
                        ->default(Auth::id())
                        ->required()
                        ->searchable()
                    : Hidden::make('user_id')
                        ->default(Auth::id())
                        ->dehydrated(),

                Select::make('system_id')
                    ->label('Sistema')
                    ->relationship(
                        'system',
                        'name',
                        fn (Builder $query) => $query->where('is_active', true)->orderBy('name')
                    )
                    ->required()
                    ->searchable()
                    ->helperText('Selecione o sistema judicial ao qual este prompt se aplica'),

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
                    ->helperText('Selecione qual inteligência artificial será utilizada para processar este prompt'),

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
                    ->helperText('Desative para manter o prompt salvo mas não utilizá-lo'),

                Toggle::make('is_default')
                    ->label('Prompt Padrão')
                    ->default(false)
                    ->helperText('Define este prompt como padrão para o sistema selecionado. Só pode haver um prompt padrão por sistema.'),
            ]);
    }
}
