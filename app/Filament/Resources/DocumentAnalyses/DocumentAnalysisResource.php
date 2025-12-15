<?php

namespace App\Filament\Resources\DocumentAnalyses;

use App\Filament\Resources\DocumentAnalyses\Pages\ListDocumentAnalyses;
use App\Filament\Resources\DocumentAnalyses\Pages\ViewDocumentAnalysis;
use App\Filament\Resources\DocumentAnalyses\Schemas\DocumentAnalysisInfolist;
use App\Filament\Resources\DocumentAnalyses\Tables\DocumentAnalysesTable;
use App\Models\DocumentAnalysis;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DocumentAnalysisResource extends Resource
{
    protected static ?string $model = DocumentAnalysis::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static ?string $navigationLabel = 'Análises de Documentos';

    protected static ?string $modelLabel = 'Análise de Documento';

    protected static ?string $pluralModelLabel = 'Análises de Documentos';

    protected static UnitEnum|string|null $navigationGroup = 'Processos';

    protected static ?int $navigationSort = 3;

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
