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

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(100)
                    ->placeholder('Ex: GPT-4o, Gemini 2.5 Flash, DeepSeek Chat'),

                Select::make('provider')
                    ->label('Provedor de I.A.')
                    ->options(AiModel::getAvailableProviders())
                    ->required()
                    ->native(false),

                TextInput::make('model_id')
                    ->label('ID do Modelo')
                    ->required()
                    ->maxLength(100)
                    ->placeholder('Ex: gpt-4o, gemini-2.5-flash-lite, deepseek-chat')
                    ->helperText('Identificador do modelo usado na API do provedor'),

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
                        'gemini' => 'info',
                        'openai' => 'success',
                        'deepseek' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('model_id')
                    ->label('ID do Modelo')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('ID copiado!'),

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
