<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\DateTimePicker;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome Completo')
                    ->required(),
                TextInput::make('email')
                    ->label('E-mail')
                    ->label('Email address')
                    ->email()
                    ->required(),
                DateTimePicker::make('email_verified_at')
                    ->label('E-mail verificado em'),
                TextInput::make('password')
                    ->label('Senha')
                    ->password()
                    ->dehydrateStateUsing(fn (null|string $state) => Hash::make($state))
                    ->dehydrated(fn (null|string $state) => filled($state))
                    ->required(fn ($context) => $context == 'create'),
                Textarea::make('two_factor_secret')
                    ->label('Autenticação com Duplo Fator')
                    ->columnSpanFull(),
                Textarea::make('two_factor_recovery_codes')
                    ->label('Códigos de Recuperação do Duplo Fator')
                    ->columnSpanFull(),
                DateTimePicker::make('two_factor_confirmed_at')
                    ->label('Duplo Fator confirmado em'),
                Select::make('roles')
                    ->relationship('roles', 'name', fn (Builder $query) => (auth()->user()->hasRole('Admin')) ? null : $query->where('name', '!=', 'Admin'))
                    ->label('Perfis')
                    ->multiple()
                    ->preload(),
            ]);
    }
}
