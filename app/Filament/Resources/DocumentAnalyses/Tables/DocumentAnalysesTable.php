<?php

namespace App\Filament\Resources\DocumentAnalyses\Tables;

use App\Jobs\ReduceDocumentAnalysisJob;
use App\Jobs\ResumeAnalysisJob;
use App\Models\DocumentAnalysis;
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
                Action::make('recover')
                    ->label('Continuar')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Continuar Análise')
                    ->modalDescription(function ($record) {
                        $stats = $record->getMicroAnalysisStats();
                        $mapCompleted = $stats['map_completed'] ?? 0;
                        $total = $record->total_documents ?? 0;
                        $failed = $stats['failed'] ?? 0;

                        $msg = "Esta ação irá continuar a análise a partir da fase de consolidação (REDUCE).\n\n";
                        $msg .= "Documentos analisados: {$mapCompleted}/{$total}";

                        if ($failed > 0) {
                            $msg .= "\nDocumentos com falha: {$failed} (serão ignorados na consolidação)";
                        }

                        return $msg;
                    })
                    ->modalSubmitActionLabel('Sim, continuar')
                    ->action(function ($record) {
                        // Verifica se fase MAP está completa
                        if (!$record->isMapPhaseComplete()) {
                            Notification::make()
                                ->title('Não é possível continuar')
                                ->body('A análise individual dos documentos ainda não foi concluída.')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Verifica se há pelo menos uma micro-análise completa
                        $completedMicros = $record->microAnalyses()
                            ->mapLevel()
                            ->completed()
                            ->count();

                        if ($completedMicros === 0) {
                            Notification::make()
                                ->title('Não é possível continuar')
                                ->body('Nenhum documento foi analisado com sucesso.')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Atualiza status para processando
                        $record->update([
                            'status' => 'processing',
                            'error_message' => null,
                            'current_phase' => DocumentAnalysis::PHASE_REDUCE,
                            'progress_message' => 'Retomando consolidação...',
                        ]);

                        // Obtém parâmetros do job
                        $jobParams = $record->job_parameters ?? [];

                        // Dispara fase REDUCE
                        ReduceDocumentAnalysisJob::dispatch(
                            $record->id,
                            $jobParams['ai_provider'] ?? 'openrouter',
                            $jobParams['deep_thinking_enabled'] ?? true,
                            $jobParams['promptTemplate'] ?? '',
                            $jobParams['ai_model_id'] ?? null,
                            1 // Começa do nível 1
                        )->onQueue('analysis');

                        Notification::make()
                            ->title('Análise retomada')
                            ->body("Consolidação de {$completedMicros} documento(s) iniciada.")
                            ->success()
                            ->send();
                    })
                    ->visible(function ($record) {
                        // Visível apenas se falhou/congelou e fase MAP está completa
                        if (!in_array($record->status, ['failed', 'processing'])) {
                            return false;
                        }

                        // Verifica se há micro-análises MAP completadas
                        $stats = $record->getMicroAnalysisStats();
                        $mapCompleted = $stats['map_completed'] ?? 0;

                        // Precisa ter pelo menos 1 micro-análise MAP completa
                        // E a fase MAP deve estar completa (não ter pendentes/processando)
                        return $mapCompleted > 0 && $record->isMapPhaseComplete();
                    }),
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
