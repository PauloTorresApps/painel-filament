<?php

namespace App\Filament\Resources\DocumentAnalyses\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DocumentAnalysisInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informações do Processo')
                    ->schema([
                        TextEntry::make('numero_processo')
                            ->label('Número do Processo')
                            ->copyable(),

                        TextEntry::make('classe_processual')
                            ->label('Classe')
                            ->placeholder('Não informado'),

                        TextEntry::make('assuntos')
                            ->label('Assuntos')
                            ->placeholder('Não informado')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull()
                    ->compact(),

                Section::make('Análise da IA')
                    ->schema([
                        TextEntry::make('ai_analysis')
                            ->label('Resultado da Análise')
                            ->markdown()
                            ->placeholder('Análise ainda não concluída'),
                    ])
                    ->visible(fn ($record) => $record->status === 'completed')
                    ->columnSpanFull(),

                Section::make('Erro')
                    ->schema([
                        TextEntry::make('error_message')
                            ->label('Mensagem de Erro')
                            ->color('danger'),
                    ])
                    ->visible(fn ($record) => $record->status === 'failed')
                    ->columnSpanFull(),
            ])
            ->columns(1);
    }
}
