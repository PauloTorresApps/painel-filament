<?php

namespace App\Filament\Resources\DocumentAnalyses\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;

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
                    ])
                    ->default('completed'), // Mostra apenas concluídos por padrão
            ])
            ->recordActions([
                ViewAction::make(),
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

                // Por padrão, oculta falhas (usuário pode exibir via filtro)
                if (!request()->has('tableFilters')) {
                    $query->where('status', '!=', 'failed');
                }

                return $query;
            });
    }
}
