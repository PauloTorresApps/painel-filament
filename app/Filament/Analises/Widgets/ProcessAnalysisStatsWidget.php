<?php

namespace App\Filament\Analises\Widgets;

use App\Models\DocumentAnalysis;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class ProcessAnalysisStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Análises de Processos';

    protected function getStats(): array
    {
        $user = Auth::user();
        $query = DocumentAnalysis::query();

        if (!$user->hasAnyRole(['Admin', 'Manager'])) {
            $query->where('user_id', $user->id);
        }

        $total = (clone $query)->count();
        $completed = (clone $query)->where('status', 'completed')->count();
        $processing = (clone $query)->where('status', 'processing')->count();
        $failed = (clone $query)->where('status', 'failed')->count();
        $pending = (clone $query)->where('status', 'pending')->count();

        $thisMonth = (clone $query)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $successRate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        return [
            Stat::make('Total', $total)
                ->description('Processos analisados')
                ->descriptionIcon('heroicon-m-document-magnifying-glass')
                ->color('primary'),

            Stat::make('Concluídas', $completed)
                ->description("{$successRate}% de sucesso")
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Processando', $processing + $pending)
                ->description($pending > 0 ? "{$pending} na fila" : 'Nenhuma na fila')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('info'),

            Stat::make('Com Falha', $failed)
                ->description($failed > 0 ? 'Necessitam atenção' : 'Nenhuma falha')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($failed > 0 ? 'danger' : 'gray'),

            Stat::make('Este Mês', $thisMonth)
                ->description(now()->translatedFormat('F Y'))
                ->descriptionIcon('heroicon-m-calendar')
                ->color('warning'),
        ];
    }
}
