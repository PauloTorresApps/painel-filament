<?php

namespace App\Filament\Analises\Resources\ProcessAnalysis\Tables;

use App\Jobs\ProcessAnalysis\BuildInventoryJob;
use App\Jobs\ProcessAnalysis\ReduceDocumentAnalysisJob;
use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
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

                TextColumn::make('total_documents')
                    ->label('Docs')
                    ->alignCenter()
                    ->sortable(),

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
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'download' => 'Download',
                        'inventory' => 'Inventário',
                        'map' => 'Análise',
                        'reduce' => 'Consolidação',
                        'chronology' => 'Cronologia',
                        'engine' => 'Engine',
                        'parecer_structured' => 'Parecer Estruturado',
                        'design' => 'Designer',
                        'completed' => 'Concluído',
                        default => '-',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'download' => 'gray',
                        'inventory' => 'gray',
                        'map' => 'info',
                        'reduce' => 'warning',
                        'chronology' => 'warning',
                        'engine' => 'danger',
                        'parecer_structured' => 'success',
                        'design' => 'success',
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
                            $processed = min($record->processed_documents_count, $record->total_documents);
                            return "{$processed}/{$record->total_documents} ({$percentage}%)";
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
                Action::make('reprocess')
                    ->label('Reprocessar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Reprocessar Análise')
                    ->modalDescription(fn ($record) => "Processo {$record->numero_processo}: escolha entre Reanalisar Completamente ou Reanalisar somente etapas com erros.")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cancelar')
                    ->extraModalFooterActions(fn (Action $action): array => [
                        $action->makeModalSubmitAction('retry_only_failed', arguments: ['retry_mode' => 'only_failed'])
                            ->label('Reanalisar somente etapas com erros')
                            ->color('warning'),
                        $action->makeModalSubmitAction('retry_all', arguments: ['retry_mode' => 'all'])
                            ->label('Reanalisar Completamente')
                            ->color('danger'),
                    ])
                    ->action(function ($record, array $arguments) {
                        $analysis = $record;
                        $retryMode = $arguments['retry_mode'] ?? 'only_failed';

                        if ($retryMode === 'all') {
                            DocumentMicroAnalysis::where('document_analysis_id', $record->id)
                                ->update([
                                    'status' => 'pending',
                                    'error_message' => null,
                                ]);
                        } else {
                            DocumentMicroAnalysis::where('document_analysis_id', $record->id)
                                ->whereIn('status', ['failed', 'cancelled', 'processing'])
                                ->where('reduce_level', 0)
                                ->update([
                                    'status' => 'pending',
                                    'error_message' => null,
                                ]);
                        }

                        // Limpa os levels progressivos
                        DocumentMicroAnalysis::where('document_analysis_id', $record->id)
                            ->where('reduce_level', '>', 0)
                            ->delete();

                        $completedCount = DocumentMicroAnalysis::where('document_analysis_id', $record->id)
                            ->where('status', 'completed')
                            ->where('reduce_level', 0)
                            ->count();

                        // Reset analysis status
                        $record->update([
                            'status' => 'processing',
                            'error_message' => null,
                            'ai_analysis' => null,
                            'current_phase' => 'map',
                            'processed_documents_count' => $completedCount,
                            'progress_message' => 'Reiniciando processamento...',
                        ]);

                        // Get job parameters
                        $jobParams = $record->job_parameters ?? [];

                        // Dispatch inventario + map
                        BuildInventoryJob::dispatch(
                            $record->id,
                            $jobParams['aiProvider'] ?? $jobParams['ai_provider'] ?? 'openrouter',
                            $jobParams['deepThinkingEnabled'] ?? $jobParams['deep_thinking_enabled'] ?? true,
                            $jobParams['contextoDados'] ?? [
                                'numero_processo' => $record->numero_processo,
                                'classe_processual' => $record->classe_processual ?? 'Não informada',
                                'assuntos' => $record->assuntos ?? 'Não informados',
                            ],
                            $jobParams['aiModelId'] ?? $jobParams['ai_model_id'] ?? null,
                            $record->user_id,
                            $jobParams['reduceStrategy'] ?? $jobParams['reduce_strategy'] ?? 'auto',
                            $jobParams['mapModelId'] ?? $jobParams['map_model_id'] ?? null
                        );

                        Notification::make()
                            ->title('Processamento reiniciado')
                            ->body('A análise foi colocada na fila para reprocessamento.')
                            ->success()
                            ->send();
                    })
                    ->visible(fn ($record) => in_array($record->status, ['failed', 'processing', 'cancelled'])),
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
                            $jobParams['aiProvider'] ?? $jobParams['ai_provider'] ?? 'openrouter',
                            $jobParams['deepThinkingEnabled'] ?? $jobParams['deep_thinking_enabled'] ?? true,
                            $jobParams['promptTemplate'] ?? '',
                            $jobParams['aiModelId'] ?? $jobParams['ai_model_id'] ?? null,
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
                DeleteAction::make()
                    ->label('Excluir')
                    ->icon('heroicon-o-trash')
                    ->requiresConfirmation()
                    ->modalHeading('Excluir Análise')
                    ->modalDescription(fn ($record) => "Esta ação é irreversível. A análise do processo {$record->numero_processo} será permanentemente excluída. Deseja continuar?")
                    ->modalSubmitActionLabel('Sim, excluir')
                    ->before(function ($record) {
                        // Delete related micro analyses first
                        DocumentMicroAnalysis::where('document_analysis_id', $record->id)->delete();
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
