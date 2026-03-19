<?php

namespace App\Console\Commands;

use App\Models\AnalysisBenchmarkSnapshot;
use Illuminate\Console\Command;

class BenchmarkTrend extends Command
{
    protected $signature = 'analysis:benchmark-trend
                            {--days=30 : Janela em dias para ler snapshots}
                            {--window-days=7 : Janela de benchmark considerada nos snapshots}
                            {--format=table : Formato de saída (table|json)}';

    protected $description = 'Mostra tendência histórica de performance com base nos snapshots persistidos';

    public function handle(): int
    {
        $historyDays = max(1, (int) $this->option('days'));
        $windowDays = max(1, (int) $this->option('window-days'));
        $format = strtolower((string) $this->option('format'));

        $snapshots = AnalysisBenchmarkSnapshot::query()
            ->where('window_days', $windowDays)
            ->whereDate('snapshot_date', '>=', now()->subDays($historyDays)->toDateString())
            ->orderBy('snapshot_date')
            ->get();

        if ($snapshots->isEmpty()) {
            if ($format === 'json') {
                $this->line(json_encode([
                    'history_days' => $historyDays,
                    'window_days' => $windowDays,
                    'snapshot_count' => 0,
                    'trend' => [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->warn('Nenhum snapshot encontrado para a janela informada.');
            }

            return self::SUCCESS;
        }

        $first = $snapshots->first();
        $last = $snapshots->last();

        $trend = [
            'snapshot_count' => $snapshots->count(),
            'date_start' => (string) $first->snapshot_date,
            'date_end' => (string) $last->snapshot_date,
            'judicial_p95_ms_first' => (int) data_get($first->metrics, 'judicial.p95_total_ms', 0),
            'judicial_p95_ms_last' => (int) data_get($last->metrics, 'judicial.p95_total_ms', 0),
            'judicial_docs_per_min_first' => (float) data_get($first->metrics, 'judicial.docs_per_min', 0),
            'judicial_docs_per_min_last' => (float) data_get($last->metrics, 'judicial.docs_per_min', 0),
            'contracts_avg_analysis_ms_first' => (int) data_get($first->metrics, 'contracts.avg_analysis_ms', 0),
            'contracts_avg_analysis_ms_last' => (int) data_get($last->metrics, 'contracts.avg_analysis_ms', 0),
            'slo_breach_snapshots' => $snapshots->filter(fn ($s) => !empty($s->slo_breaches))->count(),
        ];

        if ($format === 'json') {
            $this->line(json_encode([
                'history_days' => $historyDays,
                'window_days' => $windowDays,
                'trend' => $trend,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $this->info('Tendência de benchmark');
        $this->table(
            ['Métrica', 'Início', 'Fim'],
            [
                ['Snapshots', (string) $trend['snapshot_count'], (string) $trend['snapshot_count']],
                ['Período', $trend['date_start'], $trend['date_end']],
                ['Judicial P95 (ms)', (string) $trend['judicial_p95_ms_first'], (string) $trend['judicial_p95_ms_last']],
                ['Judicial docs/min', (string) $trend['judicial_docs_per_min_first'], (string) $trend['judicial_docs_per_min_last']],
                ['Contracts avg analysis (ms)', (string) $trend['contracts_avg_analysis_ms_first'], (string) $trend['contracts_avg_analysis_ms_last']],
                ['Snapshots com breach SLO', '0', (string) $trend['slo_breach_snapshots']],
            ]
        );

        return self::SUCCESS;
    }
}
