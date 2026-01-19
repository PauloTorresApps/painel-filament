<?php

namespace App\Filament\Analises\Widgets;

use App\Models\DocumentAnalysis;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;

class RecentProcessAnalysesWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Análises de Processos Recentes';

    public function table(Table $table): Table
    {
        $user = Auth::user();
        $query = DocumentAnalysis::query();

        if (!$user->hasAnyRole(['Admin', 'Manager'])) {
            $query->where('user_id', $user->id);
        }

        return $table
            ->query($query)
            ->heading('Análises de Processos Recentes')
            ->columns([
                TextColumn::make('numero_processo')
                    ->label('Processo')
                    ->searchable()
                    ->limit(25),

                TextColumn::make('status')
                    ->label('Status')
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

                TextColumn::make('total_documents')
                    ->label('Documentos')
                    ->alignCenter(),

                TextColumn::make('processing_time_ms')
                    ->label('Tempo')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 1000, 1) . 's' : '-'),

                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([5]);
    }
}
