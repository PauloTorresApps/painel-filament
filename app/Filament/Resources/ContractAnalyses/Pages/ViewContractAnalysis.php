<?php

namespace App\Filament\Resources\ContractAnalyses\Pages;

use App\Filament\Resources\ContractAnalyses\ContractAnalysisResource;
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
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\DeleteAction::make(),
        ];
    }
}
