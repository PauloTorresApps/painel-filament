<?php

namespace App\Filament\Resources\JudicialUsers\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Hidden;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

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
                            fn (Builder $query) => $query->orderBy('name')
                        )
                        ->default(Auth::id())
                        ->required()
                        ->searchable()
                    : Hidden::make('user_id')
                        ->default(Auth::id())
                        ->dehydrated(),

                TextInput::make('user_login')
                    ->label('Login do Webservice')
                    ->required()
                    ->maxLength(255),

                TextInput::make('system_name')
                    ->label('Nome do Sistema')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Ex: EPROC, PJE, PROJUDI, etc.'),
            ]);
    }
}
