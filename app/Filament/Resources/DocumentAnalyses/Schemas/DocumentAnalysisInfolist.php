<?php

namespace App\Filament\Resources\DocumentAnalyses\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Markdown;

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

                        TextEntry::make('descricao_documento')
                            ->label('Documento')
                            ->placeholder('-'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'completed' => 'success',
                                'processing' => 'warning',
                                'failed' => 'danger',
                                'pending' => 'gray',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'completed' => 'Concluído',
                                'processing' => 'Processando',
                                'failed' => 'Falhou',
                                'pending' => 'Pendente',
                                default => $state,
                            }),

                        TextEntry::make('total_characters')
                            ->label('Total de Caracteres')
                            ->numeric()
                            ->placeholder('-'),

                        TextEntry::make('processing_time_ms')
                            ->label('Tempo de Processamento')
                            ->formatStateUsing(fn (?int $state): string => $state ? round($state / 1000, 2) . ' segundos' : '-')
                            ->placeholder('-'),

                        TextEntry::make('created_at')
                            ->label('Criado em')
                            ->dateTime('d/m/Y H:i:s'),

                        TextEntry::make('updated_at')
                            ->label('Atualizado em')
                            ->dateTime('d/m/Y H:i:s'),
                    ])
                    ->columns(2),

                Section::make('Análise da IA')
                    ->schema([
                        TextEntry::make('ai_analysis')
                            ->label('Resultado da Análise')
                            ->markdown()
                            ->placeholder('Análise ainda não concluída')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => $record->status === 'completed'),

                Section::make('Texto Extraído')
                    ->schema([
                        TextEntry::make('extracted_text')
                            ->label('Texto do Documento')
                            ->placeholder('Texto não disponível')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),

                Section::make('Erro')
                    ->schema([
                        TextEntry::make('error_message')
                            ->label('Mensagem de Erro')
                            ->color('danger')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => $record->status === 'failed'),
            ]);
    }
}
