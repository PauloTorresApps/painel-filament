<?php

namespace App\Filament\Resources\Systems\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;

class SystemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome do Sistema')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->helperText('Ex: EPROC, SEEU, PJE, PROJUDI, etc.'),

                Textarea::make('description')
                    ->label('Descrição')
                    ->rows(3)
                    ->maxLength(500)
                    ->helperText('Descrição opcional do sistema')
                    ->columnSpanFull(),

                Toggle::make('is_active')
                    ->label('Ativo')
                    ->default(true)
                    ->helperText('Desative para impedir uso do sistema'),
            ]);
    }
}
