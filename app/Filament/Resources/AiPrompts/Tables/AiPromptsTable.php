<?php

namespace App\Filament\Resources\AiPrompts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;

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
                    ->badge(),

                TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->sortable()
                    ->limit(50),

                TextColumn::make('ai_provider')
                    ->label('IA')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => \App\Models\AiPrompt::getAvailableProviders()[$state] ?? $state)
                    ->color(fn ($record) => $record->provider_badge_color)
                    ->sortable(),

                IconColumn::make('deep_thinking_enabled')
                    ->label('Deep Think')
                    ->boolean()
                    ->sortable()
                    ->tooltip('Modo de Pensamento Profundo ativado')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('content')
                    ->label('Conteúdo')
                    ->searchable()
                    ->limit(100)
                    ->wrap(),

                IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('is_default')
                    ->label('Padrão')
                    ->boolean()
                    ->sortable(),

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
