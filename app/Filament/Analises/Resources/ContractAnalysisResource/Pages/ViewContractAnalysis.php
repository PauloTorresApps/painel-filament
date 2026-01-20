<?php

namespace App\Filament\Analises\Resources\ContractAnalysisResource\Pages;

use App\Filament\Analises\Resources\ContractAnalysisResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Infolists\Components\TextEntry;

class ViewContractAnalysis extends ViewRecord
{
    protected static string $resource = ContractAnalysisResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informações do Contrato')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('file_name')
                                    ->label('Arquivo'),

                                TextEntry::make('user.name')
                                    ->label('Usuário'),

                                TextEntry::make('created_at')
                                    ->label('Data')
                                    ->dateTime('d/m/Y H:i'),
                            ]),

                        Grid::make(4)
                            ->schema([
                                TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->formatStateUsing(fn ($record) => $record->status_label)
                                    ->color(fn ($record) => $record->status_badge_color),

                                TextEntry::make('ai_provider')
                                    ->label('IA Utilizada')
                                    ->formatStateUsing(fn ($state) => match($state) {
                                        'gemini' => 'Google Gemini',
                                        'openai' => 'OpenAI',
                                        'deepseek' => 'DeepSeek',
                                        default => $state ?? '-'
                                    }),

                                TextEntry::make('file_size')
                                    ->label('Tamanho')
                                    ->formatStateUsing(fn ($record) => $record->formatted_file_size),

                                TextEntry::make('processing_time_ms')
                                    ->label('Tempo de Processamento')
                                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 1000, 1) . ' segundos' : '-'),
                            ]),
                    ]),

                Section::make('Erro')
                    ->schema([
                        TextEntry::make('error_message')
                            ->label('')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => $record->isFailed() && $record->error_message)
                    ->collapsible(),

                Section::make('Resultado da Análise')
                    ->schema([
                        TextEntry::make('analysis_result')
                            ->label('')
                            ->markdown()
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => $record->isCompleted() && $record->analysis_result)
                    ->collapsible(),

                Section::make('Metadados da IA')
                    ->description('Informações técnicas sobre o processamento')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('analysis_ai_metadata.provider')
                                    ->label('Provedor')
                                    ->default('-'),

                                TextEntry::make('analysis_ai_metadata.model')
                                    ->label('Modelo')
                                    ->default('-'),

                                TextEntry::make('analysis_ai_metadata.api_calls_count')
                                    ->label('Chamadas API')
                                    ->default('-'),

                                TextEntry::make('analysis_ai_metadata.documents_processed')
                                    ->label('Documentos Processados')
                                    ->default('-'),
                            ]),

                        Grid::make(4)
                            ->schema([
                                TextEntry::make('analysis_ai_metadata.total_prompt_tokens')
                                    ->label('Tokens de Entrada')
                                    ->formatStateUsing(fn ($state) => $state ? number_format($state) : '-'),

                                TextEntry::make('analysis_ai_metadata.total_completion_tokens')
                                    ->label('Tokens de Saída')
                                    ->formatStateUsing(fn ($state) => $state ? number_format($state) : '-'),

                                TextEntry::make('analysis_ai_metadata.total_tokens')
                                    ->label('Total de Tokens')
                                    ->formatStateUsing(fn ($state) => $state ? number_format($state) : '-'),

                                TextEntry::make('analysis_ai_metadata.total_reasoning_tokens')
                                    ->label('Tokens de Raciocínio')
                                    ->formatStateUsing(fn ($state) => $state ? number_format($state) : '-')
                                    ->visible(fn ($record) => ($record->analysis_ai_metadata['total_reasoning_tokens'] ?? 0) > 0),
                            ]),
                    ])
                    ->visible(fn ($record) => $record->isCompleted() && !empty($record->analysis_ai_metadata))
                    ->collapsed()
                    ->collapsible(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\DeleteAction::make(),
        ];
    }
}
