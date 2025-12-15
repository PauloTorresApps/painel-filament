<?php

namespace App\Filament\Resources\DocumentAnalyses\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class DocumentAnalysisForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                TextInput::make('numero_processo')
                    ->required(),
                TextInput::make('id_documento'),
                TextInput::make('descricao_documento'),
                Textarea::make('extracted_text')
                    ->columnSpanFull(),
                Textarea::make('ai_analysis')
                    ->columnSpanFull(),
                TextInput::make('status')
                    ->required()
                    ->default('pending'),
                Textarea::make('error_message')
                    ->columnSpanFull(),
                TextInput::make('total_characters')
                    ->numeric(),
                TextInput::make('processing_time_ms')
                    ->numeric(),
            ]);
    }
}
