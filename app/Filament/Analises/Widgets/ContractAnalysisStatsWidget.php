<?php

namespace App\Filament\Analises\Widgets;

use App\Models\ContractAnalysis;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class ContractAnalysisStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Análises de Contratos';

    private const CACHE_TTL_SECONDS = 30;

    protected function getStats(): array
    {
        $user = Auth::user();
        if (!$user) {
            return [];
        }

        $query = ContractAnalysis::query();

        // Se não for Admin ou Manager, filtra pelo usuário
        if (!$user->hasAnyRole(['Admin', 'Manager'])) {
            $query->where('user_id', $user->id);
        }

        $cacheKey = $user->hasAnyRole(['Admin', 'Manager'])
            ? 'widgets:contract-stats:all'
            : 'widgets:contract-stats:user:' . $user->id;

        $stats = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($query) {
            $now = now();

            return (array) (clone $query)
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
                ->selectRaw("SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing")
                ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
                ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending")
                ->selectRaw("SUM(CASE WHEN legal_opinion_status = 'completed' THEN 1 ELSE 0 END) as legal_opinions_completed")
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
        $legalOpinionsCompleted = (int) ($stats['legal_opinions_completed'] ?? 0);
        $thisMonth = (int) ($stats['this_month'] ?? 0);

        // Taxa de sucesso
        $successRate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        return [
            Stat::make('Total de Análises', $total)
                ->description('Contratos analisados')
                ->descriptionIcon('heroicon-m-clipboard-document-check')
                ->color('primary'),

            Stat::make('Concluídas', $completed)
                ->description("{$successRate}% de sucesso")
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Em Processamento', $processing)
                ->description($pending > 0 ? "{$pending} pendentes" : 'Nenhuma pendente')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('info'),

            Stat::make('Pareceres Jurídicos', $legalOpinionsCompleted)
                ->description('Gerados com sucesso')
                ->descriptionIcon('heroicon-m-scale')
                ->color('success'),

            Stat::make('Com Falha', $failed)
                ->description('Necessitam atenção')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($failed > 0 ? 'danger' : 'gray'),

            Stat::make('Este Mês', $thisMonth)
                ->description(now()->translatedFormat('F Y'))
                ->descriptionIcon('heroicon-m-calendar')
                ->color('warning'),
        ];
    }
}
