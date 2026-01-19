<?php

namespace App\Filament\Analises\Widgets;

use App\Models\ContractAnalysis;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;

class RecentContractAnalysesWidget extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $user = Auth::user();
        $query = ContractAnalysis::query();

        if (!$user->hasAnyRole(['Admin', 'Manager'])) {
            $query->where('user_id', $user->id);
        }

        return $table
            ->query($query)
            ->heading('Análises de Contratos Recentes')
            ->columns([
                TextColumn::make('file_name')
                    ->label('Arquivo')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->file_name),

                TextColumn::make('status')
                    ->label('Análise')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Pendente',
                        'processing' => 'Processando',
                        'completed' => 'Concluída',
                        'failed' => 'Falhou',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'processing' => 'info',
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('legal_opinion_status')
                    ->label('Parecer')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'pending' => 'Pendente',
                        'processing' => 'Gerando...',
                        'completed' => 'Concluído',
                        'failed' => 'Falhou',
                        default => '-',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'pending' => 'warning',
                        'processing' => 'info',
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('ai_provider')
                    ->label('IA')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'gemini' => 'Gemini',
                        'openai' => 'OpenAI',
                        'deepseek' => 'DeepSeek',
                        default => $state ?? '-'
                    })
                    ->color('gray'),

                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([5]);
    }
}
