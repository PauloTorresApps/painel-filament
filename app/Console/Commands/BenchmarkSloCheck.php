<?php

namespace App\Console\Commands;

use App\Services\AnalysisBenchmarkService;
use App\Services\BenchmarkSloService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BenchmarkSloCheck extends Command
{
    protected $signature = 'analysis:benchmark-slo-check
                            {--days=7 : Janela de análise em dias}
                            {--format=table : Formato de saída (table|json)}
                            {--fail-on-breach : Retorna exit code 1 quando houver breach}';

    protected $description = 'Avalia SLOs de performance dos pipelines com base no benchmark atual';

    public function handle(AnalysisBenchmarkService $benchmarkService, BenchmarkSloService $sloService): int
    {
        $days = max(1, (int) $this->option('days'));
        $format = strtolower((string) $this->option('format'));

        $metrics = $benchmarkService->collect($days);
        $breaches = $sloService->evaluate($metrics);

        if ($format === 'json') {
            $this->line(json_encode([
                'window_days' => $days,
                'generated_at' => now()->toIso8601String(),
                'breaches' => $breaches,
                'breach_count' => count($breaches),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            if (empty($breaches)) {
                $this->info('SLO check sem violações.');
            } else {
                $this->warn('SLO check encontrou violações.');
                $this->table(['Métrica', 'Atual', 'Regra', 'Esperado'], array_map(function (array $breach) {
                    return [
                        $breach['key'],
                        (string) $breach['actual'],
                        $breach['comparator'],
                        (string) $breach['expected'],
                    ];
                }, $breaches));
            }
        }

        if (!empty($breaches) && $this->option('fail-on-breach')) {
            Log::warning('BenchmarkSloCheck: violações de SLO detectadas', [
                'window_days' => $days,
                'breach_count' => count($breaches),
                'breaches' => $breaches,
            ]);

            $this->notifyWebhook($days, $breaches);
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int, array{key:string, actual:float|int, expected:float|int, comparator:string}> $breaches
     */
    private function notifyWebhook(int $days, array $breaches): void
    {
        $url = (string) config('analysis.slo.alert_webhook_url');
        if ($url === '') {
            return;
        }

        try {
            Http::timeout(10)->post($url, [
                'event' => 'analysis.slo.breach',
                'window_days' => $days,
                'breach_count' => count($breaches),
                'breaches' => $breaches,
                'app' => config('app.name'),
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('BenchmarkSloCheck: falha ao enviar alerta de webhook', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
