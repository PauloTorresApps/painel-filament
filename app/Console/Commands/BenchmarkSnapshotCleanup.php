<?php

namespace App\Console\Commands;

use App\Models\AnalysisBenchmarkSnapshot;
use Illuminate\Console\Command;

class BenchmarkSnapshotCleanup extends Command
{
    protected $signature = 'analysis:benchmark-snapshot-cleanup
                            {--older-than-days=90 : Remove snapshots mais antigos que X dias}
                            {--force : Remove sem confirmação interativa}';

    protected $description = 'Limpa snapshots históricos de benchmark antigos conforme política de retenção';

    public function handle(): int
    {
        $olderThanDays = max(1, (int) $this->option('older-than-days'));
        $cutoffDate = now()->subDays($olderThanDays)->toDateString();

        $query = AnalysisBenchmarkSnapshot::query()
            ->whereDate('snapshot_date', '<', $cutoffDate);

        $count = $query->count();

        if ($count === 0) {
            $this->info('Nenhum snapshot antigo encontrado para limpeza.');
            return self::SUCCESS;
        }

        $this->warn("Serão removidos {$count} snapshot(s) anteriores a {$cutoffDate}.");

        if (!$this->option('force') && !$this->confirm('Deseja continuar?', false)) {
            $this->info('Operação cancelada.');
            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("Limpeza concluída. {$deleted} snapshot(s) removido(s).");

        return self::SUCCESS;
    }
}
