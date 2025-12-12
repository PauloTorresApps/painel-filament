<?php

namespace App\Filament\Resources\JudicialUsers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Auth;

class JudicialUsersTable
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

        $columns[] = TextColumn::make('user_login')
            ->label('Login do Webservice')
            ->searchable();

        $columns[] = TextColumn::make('system_name')
            ->label('Sistema')
            ->searchable()
            ->badge();

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
