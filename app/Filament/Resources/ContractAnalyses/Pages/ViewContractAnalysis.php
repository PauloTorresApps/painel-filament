<?php

namespace App\Filament\Resources\ContractAnalyses\Pages;

use App\Filament\Resources\ContractAnalyses\ContractAnalysisResource;
use App\Filament\Traits\HasInfographicActions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Infolists\Components\TextEntry;
use Filament\Actions\Action;

class ViewContractAnalysis extends ViewRecord
{
    use HasInfographicActions;

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
                    ->collapsible(),

                // Parecer Jurídico
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

                        $this->makeGenerateInfographicAction(),
                    ])
                    ->visible(fn ($record) => $record->isLegalOpinionCompleted() && $record->legal_opinion_result)
                    ->collapsible(),

                // Infográfico Visual Law
                Section::make('Infográfico Visual Law')
                    ->icon('heroicon-o-chart-bar')
                    ->description(fn ($record) => $record->infographic_processing_time_ms
                        ? 'Tempo de geração: ' . number_format($record->infographic_processing_time_ms / 1000, 1) . ' segundos'
                        : null)
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('infographic_ai_metadata.totals.total_prompt_tokens')
                                    ->label('Tokens Entrada')
                                    ->formatStateUsing(fn ($state) => number_format($state ?? 0)),

                                TextEntry::make('infographic_ai_metadata.totals.total_completion_tokens')
                                    ->label('Tokens Saída')
                                    ->formatStateUsing(fn ($state) => number_format($state ?? 0)),

                                TextEntry::make('infographic_ai_metadata.totals.total_tokens')
                                    ->label('Total Tokens')
                                    ->formatStateUsing(fn ($state) => number_format($state ?? 0)),

                                TextEntry::make('infographic_ai_metadata.totals.api_calls_count')
                                    ->label('Chamadas API')
                                    ->formatStateUsing(fn ($state) => $state ?? '-'),
                            ])
                            ->visible(fn ($record) => !empty($record->infographic_ai_metadata)),
                    ])
                    ->headerActions([
                        Action::make('viewInfographic')
                            ->label('Visualizar Infográfico')
                            ->icon('heroicon-o-eye')
                            ->color('success')
                            ->url(fn ($record) => route('contracts.infographic.view', $record->id))
                            ->openUrlInNewTab(),

                        Action::make('downloadInfographic')
                            ->label('Download HTML')
                            ->icon('heroicon-o-arrow-down-tray')
                            ->color('gray')
                            ->url(fn ($record) => route('contracts.infographic.download', $record->id)),
                    ])
                    ->visible(fn ($record) => $record->isInfographicCompleted() && $record->infographic_html_result)
                    ->collapsible(),

                // Infográfico em processamento
                Section::make('Infográfico em Processamento')
                    ->icon('heroicon-o-chart-bar')
                    ->schema([
                        TextEntry::make('infographic_status')
                            ->label('')
                            ->formatStateUsing(fn () => 'O infográfico está sendo gerado. Atualize a página para verificar o status.')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => $record->isInfographicProcessing()),

                // Erro no Infográfico
                Section::make('Erro no Infográfico')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->schema([
                        TextEntry::make('infographic_error')
                            ->label('Mensagem de Erro')
                            ->columnSpanFull(),
                    ])
                    ->headerActions([
                        $this->makeRetryInfographicAction(),
                    ])
                    ->visible(fn ($record) => $record->isInfographicFailed() || $record->isInfographicCancelled()),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\DeleteAction::make(),
        ];
    }
}
