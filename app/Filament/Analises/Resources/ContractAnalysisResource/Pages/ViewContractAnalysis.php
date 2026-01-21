<?php

namespace App\Filament\Analises\Resources\ContractAnalysisResource\Pages;

use App\Filament\Analises\Resources\ContractAnalysisResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Infolists\Components\TextEntry;
use Filament\Actions\Action;

class ViewContractAnalysis extends ViewRecord
{
    protected static string $resource = ContractAnalysisResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                // 1. Informações do Contrato
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

                        // Mensagem de erro (se houver)
                        TextEntry::make('error_message')
                            ->label('Erro')
                            ->visible(fn ($record) => $record->isFailed() && $record->error_message)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                // 2. Resultado da Análise (recolhido)
                Section::make('Resultado da Análise')
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        TextEntry::make('analysis_result')
                            ->label('')
                            ->markdown()
                            ->columnSpanFull(),
                    ])
                    ->headerActions([
                        Action::make('viewAnalysisPdf')
                            ->label('Visualizar PDF')
                            ->icon('heroicon-o-eye')
                            ->color('gray')
                            ->url(fn ($record) => route('contracts.analysis.view', $record->id))
                            ->openUrlInNewTab(),

                        Action::make('downloadAnalysisPdf')
                            ->label('Download PDF')
                            ->icon('heroicon-o-arrow-down-tray')
                            ->color('primary')
                            ->url(fn ($record) => route('contracts.analysis.download', $record->id)),
                    ])
                    ->visible(fn ($record) => $record->isCompleted() && $record->analysis_result)
                    ->collapsed()
                    ->collapsible()
                    ->columnSpanFull(),

                // 3. Parecer Jurídico (aberto)
                Section::make('Parecer Jurídico')
                    ->icon('heroicon-o-scale')
                    ->schema([
                        TextEntry::make('legal_opinion_result')
                            ->label('')
                            ->markdown()
                            ->columnSpanFull(),
                    ])
                    ->headerActions([
                        Action::make('viewLegalOpinionPdf')
                            ->label('Visualizar PDF')
                            ->icon('heroicon-o-eye')
                            ->color('gray')
                            ->url(fn ($record) => route('contracts.legal-opinion.view', $record->id))
                            ->openUrlInNewTab(),

                        Action::make('downloadLegalOpinionPdf')
                            ->label('Download PDF')
                            ->icon('heroicon-o-arrow-down-tray')
                            ->color('primary')
                            ->url(fn ($record) => route('contracts.legal-opinion.download', $record->id)),
                    ])
                    ->visible(fn ($record) => $record->isLegalOpinionCompleted() && $record->legal_opinion_result)
                    ->collapsible()
                    ->columnSpanFull(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\DeleteAction::make(),
        ];
    }
}
