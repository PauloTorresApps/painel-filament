<?php

namespace App\Filament\Resources\JudicialUsers;

use App\Filament\Resources\JudicialUsers\Pages\CreateJudicialUser;
use App\Filament\Resources\JudicialUsers\Pages\EditJudicialUser;
use App\Filament\Resources\JudicialUsers\Pages\ListJudicialUsers;
use App\Filament\Resources\JudicialUsers\Schemas\JudicialUserForm;
use App\Filament\Resources\JudicialUsers\Tables\JudicialUsersTable;
use App\Models\JudicialUser;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class JudicialUserResource extends Resource
{
    protected static ?string $model = JudicialUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Usuários Judiciais';

    protected static ?string $modelLabel = 'Usuário Judicial';

    protected static ?string $pluralModelLabel = 'Usuários Judiciais';

    protected static UnitEnum|string|null $navigationGroup = 'Configurações';

    public static function form(Schema $schema): Schema
    {
        return JudicialUserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JudicialUsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJudicialUsers::route('/'),
            'create' => CreateJudicialUser::route('/create'),
            'edit' => EditJudicialUser::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = Auth::user();

        // Se o usuário não é Admin nem Manager, mostra apenas seus próprios registros
        if (!$user->hasRole(['Admin', 'Manager'])) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }
}
