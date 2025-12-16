<?php

namespace App\Filament\Resources\AiPrompts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Illuminate\Support\Facades\Auth;

class AiPromptsTable
{
    public static function configure(Table $table): Table
    {
        $user = Auth::user();
        $isAdminOrManager = $user->hasRole(['Admin', 'Manager']);

        $columns = [];

        // Mostra coluna de usuário apenas para Admin/Manager
        if ($isAdminOrManager) {
            $columns[] = TextColumn::make('user.name')
                ->label('Usuário')
                ->searchable()
                ->sortable();
        }

        $columns[] = TextColumn::make('system.name')
            ->label('Sistema')
            ->searchable()
            ->sortable()
            ->badge();

        $columns[] = TextColumn::make('title')
            ->label('Título')
            ->searchable()
            ->sortable()
            ->limit(50);

        $columns[] = TextColumn::make('ai_provider')
            ->label('IA')
            ->formatStateUsing(fn (string $state): string => \App\Models\AiPrompt::getAvailableProviders()[$state] ?? $state)
            ->badge()
            ->color(fn (string $state): string => match ($state) {
                'gemini' => 'success',
                'deepseek' => 'info',
                default => 'gray',
            })
            ->sortable();

        $columns[] = IconColumn::make('deep_thinking_enabled')
            ->label('Deep Think')
            ->boolean()
            ->sortable()
            ->tooltip('Modo de Pensamento Profundo ativado')
            ->toggleable(isToggledHiddenByDefault: false);

        $columns[] = TextColumn::make('content')
            ->label('Conteúdo')
            ->searchable()
            ->limit(100)
            ->wrap();

        $columns[] = IconColumn::make('is_active')
            ->label('Ativo')
            ->boolean()
            ->sortable();

        $columns[] = IconColumn::make('is_default')
            ->label('Padrão')
            ->boolean()
            ->sortable();

        $columns[] = TextColumn::make('created_at')
            ->label('Criado em')
            ->dateTime('d/m/Y H:i')
            ->sortable()
            ->toggleable(isToggledHiddenByDefault: true);

        $columns[] = TextColumn::make('updated_at')
            ->label('Atualizado em')
            ->dateTime('d/m/Y H:i')
            ->sortable()
            ->toggleable(isToggledHiddenByDefault: true);

        return $table
            ->columns($columns)
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
