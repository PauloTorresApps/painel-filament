<?php

namespace App\Filament\Resources\ContractAnalysis;

use App\Filament\Resources\ContractAnalysis\Pages\ListContractAnalyses;
use App\Filament\Resources\ContractAnalysis\Pages\ViewContractAnalysis;
use App\Models\ContractAnalysis;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Filters\SelectFilter;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ContractAnalysisResource extends Resource
{
    protected static ?string $model = ContractAnalysis::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static ?string $navigationLabel = 'Histórico de Contratos';

    protected static ?string $modelLabel = 'Análise de Contrato';

    protected static ?string $pluralModelLabel = 'Análises de Contratos';

    protected static UnitEnum|string|null $navigationGroup = 'Contratos';

    protected static ?int $navigationSort = 2;

    /**
     * Controle de acesso ao resource
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        return $user->hasRole(['Admin', 'Manager', 'Analista de Contrato']);
    }

    /**
     * Filtra registros baseado no usuário
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        // Admin e Manager veem todas as análises
        if ($user->hasRole(['Admin', 'Manager'])) {
            return $query;
        }

        // Outros usuários veem apenas suas próprias análises
        return $query->where('user_id', $user->id);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('file_name')
                    ->label('Arquivo')
                    ->searchable()
                    ->sortable()
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->file_name),

                TextColumn::make('user.name')
                    ->label('Usuário')
                    ->searchable()
                    ->sortable()
                    ->visible(fn () => Auth::user()->hasRole(['Admin', 'Manager'])),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($record) => $record->status_label)
                    ->color(fn ($record) => $record->status_badge_color)
                    ->sortable(),

                TextColumn::make('ai_provider')
                    ->label('IA')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'gemini' => 'Gemini',
                        'openai' => 'OpenAI',
                        'deepseek' => 'DeepSeek',
                        default => $state ?? '-'
                    })
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('file_size')
                    ->label('Tamanho')
                    ->formatStateUsing(fn ($record) => $record->formatted_file_size)
                    ->sortable(),

                TextColumn::make('processing_time_ms')
                    ->label('Tempo')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 1000, 1) . 's' : '-')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pendente',
                        'processing' => 'Processando',
                        'completed' => 'Concluída',
                        'failed' => 'Falhou',
                    ]),

                SelectFilter::make('ai_provider')
                    ->label('IA')
                    ->options([
                        'gemini' => 'Gemini',
                        'openai' => 'OpenAI',
                        'deepseek' => 'DeepSeek',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContractAnalyses::route('/'),
            'view' => ViewContractAnalysis::route('/{record}'),
        ];
    }
}
