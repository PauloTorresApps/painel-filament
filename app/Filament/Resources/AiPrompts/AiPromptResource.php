<?php

namespace App\Filament\Resources\AiPrompts;

use App\Filament\Resources\AiPrompts\Pages\CreateAiPrompt;
use App\Filament\Resources\AiPrompts\Pages\EditAiPrompt;
use App\Filament\Resources\AiPrompts\Pages\ListAiPrompts;
use App\Filament\Resources\AiPrompts\Schemas\AiPromptForm;
use App\Filament\Resources\AiPrompts\Tables\AiPromptsTable;
use App\Models\AiPrompt;
use App\Models\System;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;
use Illuminate\Database\Eloquent\Builder;

class AiPromptResource extends Resource
{
    protected static ?string $model = AiPrompt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'Prompts de Análise Processual';

    protected static ?string $modelLabel = 'Prompt de Análise Processual';

    protected static ?string $pluralModelLabel = 'Prompts de Análise Processual';

    protected static UnitEnum|string|null $navigationGroup = 'Processos';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return AiPromptForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiPromptsTable::configure($table);
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
            'index' => ListAiPrompts::route('/'),
            'create' => CreateAiPrompt::route('/create'),
            'edit' => EditAiPrompt::route('/{record}/edit'),
        ];
    }

    /**
     * Obtém o ID do sistema de Contratos para exclusão
     */
    public static function getContractSystemId(): ?int
    {
        static $systemId = null;

        if ($systemId === null) {
            $system = System::where('name', 'Contratos')->first();
            $systemId = $system?->id;
        }

        return $systemId;
    }

    public static function getEloquentQuery(): Builder
    {
        $contractSystemId = static::getContractSystemId();

        // Filtra prompts que NÃO sejam do sistema de Contratos
        // Prompts de análise processual pertencem a sistemas judiciais (EPROC, PJE, etc.)
        return parent::getEloquentQuery()
            ->when($contractSystemId, fn ($query) => $query->where('system_id', '!=', $contractSystemId));
    }
}
