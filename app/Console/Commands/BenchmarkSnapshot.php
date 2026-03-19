<?php

namespace App\Console\Commands;

use App\Models\AnalysisBenchmarkSnapshot;
use App\Services\AnalysisBenchmarkService;
use App\Services\BenchmarkSloService;
use Illuminate\Console\Command;

class BenchmarkSnapshot extends Command
{
    protected $signature = 'analysis:benchmark-snapshot
                            {--days=7 : Janela de análise em dias}
                            {--check-slo : Calcula também breaches de SLO no snapshot}';

    protected $description = 'Persiste snapshot histórico dos benchmarks de performance dos pipelines';

    public function handle(AnalysisBenchmarkService $benchmarkService, BenchmarkSloService $sloService): int
    {
        $days = max(1, (int) $this->option('days'));

        $metrics = $benchmarkService->collect($days);
        $breaches = null;

        if ($this->option('check-slo')) {
            $breaches = $sloService->evaluate($metrics);
        }

        AnalysisBenchmarkSnapshot::query()->create([
            'snapshot_date' => now()->toDateString(),
            'window_days' => $days,
            'metrics' => $metrics,
            'slo_breaches' => $breaches,
        ]);

        $this->info('Snapshot de benchmark salvo com sucesso.');
        $this->line('Janela: ' . $days . ' dia(s).');
        $this->line('Breaches de SLO: ' . ($breaches === null ? 'n/a' : count($breaches)));

        return self::SUCCESS;
    }
}
