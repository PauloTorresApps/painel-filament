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
