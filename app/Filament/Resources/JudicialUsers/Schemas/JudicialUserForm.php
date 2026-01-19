<?php

namespace App\Filament\Resources\JudicialUsers\Schemas;

use App\Models\JudicialUser;
use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class JudicialUserForm
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
                            fn (Builder $query) => $query
                                ->whereHas('roles', fn (Builder $q) => $q->where('name', 'Analista de Processo'))
                                ->orderBy('name')
                        )
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
                    ->helperText('Selecione o sistema judicial')
                    ->unique(
                        table: JudicialUser::class,
                        column: 'system_id',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('user_id', $get('user_id'))
                    )
                    ->validationMessages([
                        'unique' => 'Já existe um usuário judicial cadastrado para este sistema.',
                    ]),

                TextInput::make('user_login')
                    ->label('Login do Webservice')
                    ->required()
                    ->maxLength(255),

                Toggle::make('is_default')
                    ->label('Usuário Padrão')
                    ->helperText('Marque esta opção para usar este usuário automaticamente nas consultas de processos')
                    ->default(false)
                    ->inline(false),
            ]);
    }
}
