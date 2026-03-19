<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Limpeza de arquivos de contratos finalizados - diariamente às 03:00
Schedule::command('contracts:cleanup')->dailyAt('03:00');

// Limpeza de análises falhas com mais de 30 dias - semanalmente aos domingos às 04:00
Schedule::command('analysis:cleanup --all --older-than=30')->weeklyOn(0, '04:00');

// Snapshot diário de benchmark de performance - diariamente às 05:10
$benchmarkDays = (int) config('analysis.benchmark.default_window_days', 7);
$benchmarkRetentionDays = (int) config('analysis.benchmark.retention_days', 90);

Schedule::command("analysis:benchmark-snapshot --days={$benchmarkDays} --check-slo")
    ->dailyAt('05:10')
    ->withoutOverlapping();

// Verificação horária de SLOs (retorna código de falha quando houver violações)
Schedule::command("analysis:benchmark-slo-check --days={$benchmarkDays} --fail-on-breach")
    ->hourly()
    ->withoutOverlapping();

// Limpeza semanal de snapshots históricos de benchmark
Schedule::command("analysis:benchmark-snapshot-cleanup --older-than-days={$benchmarkRetentionDays} --force")
    ->weeklyOn(0, '05:40')
    ->withoutOverlapping();
