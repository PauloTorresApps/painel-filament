<?php

namespace App\Filament\Resources\DocumentAnalyses\Tables;

use App\Jobs\ResumeAnalysisJob;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DocumentAnalysesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('numero_processo')
                    ->label('Número do Processo')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('descricao_documento')
                    ->label('Documento')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();
                        if (strlen($state) > 50) {
                            return $state;
                        }
                        return null;
                    }),

                TextColumn::make('status')
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
                    })
                    ->sortable(),

                TextColumn::make('current_phase')
                    ->label('Fase')
                    ->badge()
                    ->formatStateUsing(fn (?string $state, $record): string => match ($state) {
                        'download' => 'Download',
                        'map' => 'Análise Individual',
                        'reduce' => 'Consolidação',
                        'completed' => 'Concluído',
                        default => '-',
                    })
                    ->description(fn ($record): ?string => $record->progress_message)
                    ->color(fn (?string $state): string => match ($state) {
                        'download' => 'gray',
                        'map' => 'info',
                        'reduce' => 'warning',
                        'completed' => 'success',
                        default => 'gray',
                    })
                    ->toggleable(),

                TextColumn::make('progress')
                    ->label('Progresso')
                    ->state(function ($record): string {
                        if ($record->status === 'processing' && $record->current_phase) {
                            $percentage = round($record->getOverallProgressPercentage());
                            return "{$percentage}%";
                        }
                        if ($record->total_documents > 0) {
                            $percentage = $record->getProgressPercentage();
                            return "{$record->processed_documents_count}/{$record->total_documents} ({$percentage}%)";
                        }
                        return '-';
                    })
                    ->badge()
                    ->color(function ($record): string {
                        if ($record->total_documents === 0) {
                            return 'gray';
                        }
                        $percentage = $record->status === 'processing' && $record->current_phase
                            ? $record->getOverallProgressPercentage()
                            : $record->getProgressPercentage();
                        if ($percentage >= 100) {
                            return 'success';
                        } elseif ($percentage >= 50) {
                            return 'warning';
                        } else {
                            return 'info';
                        }
                    })
                    ->toggleable(),

                TextColumn::make('total_characters')
                    ->label('Caracteres')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('processing_time_ms')
                    ->label('Tempo (s)')
                    ->formatStateUsing(fn (?int $state): string => $state ? round($state / 1000, 2) . 's' : '-')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'completed' => 'Concluído',
                        'processing' => 'Processando',
                        'failed' => 'Falhou',
                        'pending' => 'Pendente',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('resume')
                    ->label('Retomar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Retomar Análise')
                    ->modalDescription(fn ($record) => "Deseja retomar a análise do processo {$record->numero_processo} de onde parou? Progresso atual: {$record->getProgressPercentage()}%")
                    ->action(function ($record) {
                        if (!$record->canBeResumed()) {
                            Notification::make()
                                ->title('Não é Possível Retomar')
                                ->body('Esta análise não pode ser retomada. Status: ' . $record->status)
                                ->warning()
                                ->send();
                            return;
                        }

                        ResumeAnalysisJob::dispatch($record->id);

                        Notification::make()
                            ->title('Retomada Iniciada')
                            ->body("A análise será retomada de onde parou ({$record->getProgressPercentage()}%)")
                            ->success()
                            ->send();
                    })
                    ->visible(fn ($record) => $record->status === 'failed' && $record->is_resumable && $record->processed_documents_count < $record->total_documents),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(function ($query) {
                // Só mostra análises do usuário logado
                $query->where('user_id', auth()->user()->id);

                return $query;
            });
    }
}
