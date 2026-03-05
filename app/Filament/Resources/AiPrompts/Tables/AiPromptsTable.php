<?php

namespace App\Filament\Resources\AiPrompts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ToggleColumn;

class AiPromptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('system.name')
                    ->label('Sistema')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('prompt_type_label')
                    ->label('Finalidade')
                    ->badge()
                    ->color(fn ($record) => match ($record->prompt_type) {
                        'document_analysis' => 'info',
                        'final_opinion' => 'success',
                        default => 'gray',
                    })
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('prompt_type', $direction))
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('aiModel.name')
                    ->label('Modelo')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('ai_provider')
                    ->label('IA')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => \App\Models\AiPrompt::getAvailableProviders()[$state] ?? $state)
                    ->color(fn ($record) => $record->provider_badge_color)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('temperature')
                    ->label('Temp.')
                    ->formatStateUsing(fn ($state) => is_null($state) ? '-' : number_format((float) $state, 1, ',', '.'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                IconColumn::make('deep_thinking_enabled')
                    ->label('Deep Think')
                    ->boolean()
                    ->sortable()
                    ->tooltip('Modo de Pensamento Profundo ativado')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('content')
                    ->label('Conteúdo')
                    ->searchable()
                    ->limit(100)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                ToggleColumn::make('is_active')
                    ->label('Ativo')
                    ->sortable()
                    ->afterStateUpdated(function ($record, $state) {
                        // Se desativando um prompt padrão, avisa
                        if (!$state && $record->is_default) {
                            \Filament\Notifications\Notification::make()
                                ->warning()
                                ->title('Atenção')
                                ->body('Você desativou um prompt que era padrão.')
                                ->send();
                        }
                    })
                    ->toggleable(isToggledHiddenByDefault: false),

                ToggleColumn::make('is_default')
                    ->label('Padrão')
                    ->sortable()
                    ->beforeStateUpdated(function ($record, $state) {
                        // Se ativando como padrão, garante que o prompt esteja ativo
                        if ($state && !$record->is_active) {
                            $record->update(['is_active' => true]);
                        }
                    })
                    ->toggleable(isToggledHiddenByDefault: false),

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
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
