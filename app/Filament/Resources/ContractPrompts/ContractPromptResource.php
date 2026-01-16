<?php

namespace App\Filament\Resources\ContractPrompts;

use App\Filament\Resources\ContractPrompts\Pages\CreateContractPrompt;
use App\Filament\Resources\ContractPrompts\Pages\EditContractPrompt;
use App\Filament\Resources\ContractPrompts\Pages\ListContractPrompts;
use App\Models\AiPrompt;
use App\Models\System;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Hidden;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use UnitEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ContractPromptResource extends Resource
{
    protected static ?string $model = AiPrompt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Prompts de Contratos';

    protected static ?string $modelLabel = 'Prompt de Contrato';

    protected static ?string $pluralModelLabel = 'Prompts de Contratos';

    protected static UnitEnum|string|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'contract-prompts';

    /**
     * Obtém o ID do sistema de Contratos
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
                Hidden::make('system_id')
                    ->default(fn () => static::getContractSystemId()),

                Select::make('prompt_type')
                    ->label('Tipo de Prompt')
                    ->options(AiPrompt::getContractPromptTypes())
                    ->default(AiPrompt::TYPE_ANALYSIS)
                    ->required()
                    ->native(false)
                    ->helperText('Selecione o tipo de prompt: Análise de Contrato ou Parecer Jurídico'),

                TextInput::make('title')
                    ->label('Título')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Dê um nome descritivo para identificar este prompt'),

                Select::make('ai_provider')
                    ->label('Provedor de IA')
                    ->options(AiPrompt::getAvailableProviders())
                    ->default('gemini')
                    ->required()
                    ->native(false)
                    ->helperText('Selecione qual inteligência artificial será utilizada'),

                Textarea::make('content')
                    ->label('Conteúdo do Prompt')
                    ->required()
                    ->rows(12)
                    ->maxLength(10000)
                    ->helperText('Digite o texto do prompt que será enviado para a IA.')
                    ->columnSpanFull(),

                Toggle::make('is_default')
                    ->label('Prompt Padrão')
                    ->default(false)
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set) {
                        if ($state === true) {
                            $set('is_active', true);
                        }
                    })
                    ->helperText('Define este prompt como padrão para o tipo selecionado. Só pode haver um prompt padrão por tipo.'),

                Toggle::make('is_active')
                    ->label('Ativo')
                    ->default(true)
                    ->reactive()
                    ->disabled(fn ($get) => $get('is_default') === true)
                    ->helperText('Prompts padrão são sempre ativos. Desative para arquivar o prompt.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('prompt_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => AiPrompt::getContractPromptTypes()[$state] ?? $state)
                    ->color(fn ($state) => $state === AiPrompt::TYPE_ANALYSIS ? 'info' : 'warning')
                    ->sortable(),

                TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->sortable()
                    ->limit(50),

                TextColumn::make('ai_provider')
                    ->label('Provedor de IA')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AiPrompt::getAvailableProviders()[$state] ?? $state)
                    ->color(fn ($record) => $record->provider_badge_color)
                    ->sortable(),

                TextColumn::make('content')
                    ->label('Conteúdo')
                    ->searchable()
                    ->limit(80)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(function ($record) {
                        if (!$record->is_active) {
                            return 'Inativo';
                        }
                        return $record->is_default ? 'Padrão' : 'Ativo';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Padrão' => 'success',
                        'Ativo' => 'gray',
                        'Inativo' => 'danger',
                    })
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('is_default', $direction)),

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
                \Filament\Tables\Filters\SelectFilter::make('prompt_type')
                    ->label('Tipo')
                    ->options(AiPrompt::getContractPromptTypes()),
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
            ->defaultSort('is_default', 'desc');
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
            'index' => ListContractPrompts::route('/'),
            'create' => CreateContractPrompt::route('/create'),
            'edit' => EditContractPrompt::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $systemId = static::getContractSystemId();

        return parent::getEloquentQuery()
            ->where('system_id', $systemId);
    }
}
