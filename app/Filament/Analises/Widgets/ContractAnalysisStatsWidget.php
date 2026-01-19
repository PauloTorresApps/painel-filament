<?php

namespace App\Filament\Analises\Widgets;

use App\Models\ContractAnalysis;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class ContractAnalysisStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Análises de Contratos';

    protected function getStats(): array
    {
        $user = Auth::user();
        $query = ContractAnalysis::query();

        // Se não for Admin ou Manager, filtra pelo usuário
        if (!$user->hasAnyRole(['Admin', 'Manager'])) {
            $query->where('user_id', $user->id);
        }

        $total = (clone $query)->count();
        $completed = (clone $query)->where('status', 'completed')->count();
        $processing = (clone $query)->where('status', 'processing')->count();
        $failed = (clone $query)->where('status', 'failed')->count();
        $pending = (clone $query)->where('status', 'pending')->count();

        // Pareceres jurídicos concluídos
        $legalOpinionsCompleted = (clone $query)
            ->where('legal_opinion_status', 'completed')
            ->count();

        // Estatísticas do mês atual
        $thisMonth = (clone $query)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        // Taxa de sucesso
        $successRate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        return [
            Stat::make('Total de Análises', $total)
                ->description('Contratos analisados')
                ->descriptionIcon('heroicon-m-document-chart-bar')
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
