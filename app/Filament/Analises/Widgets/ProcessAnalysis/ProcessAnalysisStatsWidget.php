<?php

namespace App\Filament\Analises\Widgets\ProcessAnalysis;

use App\Models\DocumentAnalysis;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class ProcessAnalysisStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Análises de Processos';

    private const CACHE_TTL_SECONDS = 30;

    protected function getStats(): array
    {
        $user = Auth::user();
        if (!$user) {
            return [];
        }

        $query = DocumentAnalysis::query();

        if (!$user->hasAnyRole(['Admin', 'Manager'])) {
            $query->where('user_id', $user->id);
        }

        $cacheKey = $user->hasAnyRole(['Admin', 'Manager'])
            ? 'widgets:process-stats:all'
            : 'widgets:process-stats:user:' . $user->id;

        $stats = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($query) {
            $now = now();

            return (array) (clone $query)
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
                ->selectRaw("SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing")
                ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
                ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending")
                ->selectRaw(
                    'SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) as this_month',
                    [
                        $now->copy()->startOfMonth(),
                        $now->copy()->addMonthNoOverflow()->startOfMonth(),
                    ]
                )
                ->first()
                ?->toArray();
        });

        $total = (int) ($stats['total'] ?? 0);
        $completed = (int) ($stats['completed'] ?? 0);
        $processing = (int) ($stats['processing'] ?? 0);
        $failed = (int) ($stats['failed'] ?? 0);
        $pending = (int) ($stats['pending'] ?? 0);
        $thisMonth = (int) ($stats['this_month'] ?? 0);

        $successRate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        return [
            Stat::make('Total', $total)
                ->description('Processos analisados')
                ->descriptionIcon('heroicon-m-square-3-stack-3d')
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
