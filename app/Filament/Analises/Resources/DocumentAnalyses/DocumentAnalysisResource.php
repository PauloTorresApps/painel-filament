<?php

namespace App\Filament\Analises\Resources\DocumentAnalyses;

use App\Filament\Analises\Resources\DocumentAnalyses\Pages\ListDocumentAnalyses;
use App\Filament\Analises\Resources\DocumentAnalyses\Pages\ViewDocumentAnalysis;
use App\Filament\Analises\Resources\DocumentAnalyses\Schemas\DocumentAnalysisInfolist;
use App\Filament\Analises\Resources\DocumentAnalyses\Tables\DocumentAnalysesTable;
use App\Models\DocumentAnalysis;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class DocumentAnalysisResource extends Resource
{
    protected static ?string $model = DocumentAnalysis::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static ?string $navigationLabel = 'Histórico de Processos';

    protected static ?string $modelLabel = 'Análise de Processo';

    protected static ?string $pluralModelLabel = 'Análises de Processos';

    protected static UnitEnum|string|null $navigationGroup = 'Processos';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'historico-processos';

    /**
     * Controle de acesso ao resource
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        return $user->hasRole(['Admin', 'Manager', 'Default', 'Analista de Processo']);
    }

    /**
     * Filtra registros baseado no usuário
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        // Se não há usuário autenticado, retorna query vazia
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        // Admin e Manager veem todas as análises
        if ($user->hasRole(['Admin', 'Manager'])) {
            return $query;
        }

        // Outros usuários veem apenas suas próprias análises
        return $query->where('user_id', $user->id);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DocumentAnalysisInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocumentAnalysesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentAnalyses::route('/'),
            'view' => ViewDocumentAnalysis::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // Análises são criadas automaticamente via Job
    }
}
