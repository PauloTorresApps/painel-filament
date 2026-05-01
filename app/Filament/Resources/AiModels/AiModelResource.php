<?php

namespace App\Filament\Resources\AiModels;

use App\Filament\Resources\AiModels\Pages\CreateAiModel;
use App\Filament\Resources\AiModels\Pages\EditAiModel;
use App\Filament\Resources\AiModels\Pages\ListAiModels;
use App\Models\AiModel;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class AiModelResource extends Resource
{
    protected static ?string $model = AiModel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $navigationLabel = 'Modelos de I.A.';

    protected static ?string $modelLabel = 'Modelo de I.A.';

    protected static ?string $pluralModelLabel = 'Modelos de I.A.';

    protected static UnitEnum|string|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        return $user->hasRole(['Admin', 'Manager']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(100)
                    ->placeholder('Nome descritivo do modelo'),

                Select::make('provider')
                    ->label('Provedor de I.A.')
                    ->options(AiModel::getAvailableProviders())
                    ->required()
                    ->native(false),

                TextInput::make('model_id')
                    ->label('ID do Modelo')
                    ->required()
                    ->maxLength(100)
                    ->placeholder('provider/nome-do-modelo')
                    ->helperText('Identificador do modelo no OpenRouter (formato: provider/modelo)'),

                Toggle::make('supports_reasoning')
                    ->label('Suporta Reasoning')
                    ->default(false)
                    ->helperText('Habilite se o modelo suporta modo de pensamento profundo (reasoning/chain-of-thought)'),

                Toggle::make('supports_vision')
                    ->label('Suporta Visão')
                    ->default(false)
                    ->helperText('Habilite se o modelo suporta análise de imagens (multimodal)'),

                Select::make('purpose')
                    ->label('Propósito')
                    ->multiple()
                    ->options(AiModel::getAvailablePurposes())
                    ->native(false)
                    ->live()
                    ->helperText(function ($record) {
                        $assignments = AiModel::getCurrentPurposeAssignments($record?->id);
                        if (empty($assignments)) {
                            return 'Nenhum propósito atribuído atualmente. Cada propósito só pode ser vinculado a um modelo ativo por vez.';
                        }

                        $lines = [];
                        foreach ($assignments as $purpose => $modelName) {
                            $purposeLabel = AiModel::getAvailablePurposes()[$purpose] ?? $purpose;
                            $lines[] = "**{$purposeLabel}** → {$modelName}";
                        }

                        return 'Atribuições atuais: ' . implode(' | ', $lines) . '. Ao selecionar um propósito já atribuído, ele será transferido para este modelo.';
                    }),

                Textarea::make('description')
                    ->label('Descrição')
                    ->rows(3)
                    ->placeholder('Descrição opcional do modelo e suas características'),

                Toggle::make('is_active')
                    ->label('Ativo')
                    ->default(true)
                    ->helperText('Modelos inativos não aparecem nas opções de seleção'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('provider')
                    ->label('Provedor')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AiModel::getAvailableProviders()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'openrouter' => 'primary',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('model_id')
                    ->label('ID do Modelo')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('ID copiado!'),

                Tables\Columns\TextColumn::make('purpose')
                    ->label('Propósito')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AiModel::getAvailablePurposes()[$state] ?? $state)
                    ->color('info'),

                Tables\Columns\IconColumn::make('supports_reasoning')
                    ->label('Reasoning')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\IconColumn::make('supports_vision')
                    ->label('Visão')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('prompts_count')
                    ->label('Prompts')
                    ->counts('prompts')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('provider')
                    ->label('Provedor')
                    ->options(AiModel::getAvailableProviders()),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Todos')
                    ->trueLabel('Apenas ativos')
                    ->falseLabel('Apenas inativos'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiModels::route('/'),
            'create' => CreateAiModel::route('/create'),
            'edit' => EditAiModel::route('/{record}/edit'),
        ];
    }
}
