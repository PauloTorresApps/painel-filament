<?php

namespace App\Console\Commands;

use App\Models\DocumentAnalysis;
use Illuminate\Console\Command;

class CleanupStuckAnalyses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'analyses:cleanup-stuck {--timeout=30 : Timeout em minutos para considerar análise travada}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Marca análises travadas com status "processing" como "failed" após timeout';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $timeoutMinutes = (int) $this->option('timeout');

        $this->info("🔍 Buscando análises travadas (timeout: {$timeoutMinutes} minutos)...");

        // Busca análises em processing há mais tempo que o timeout
        $stuckAnalyses = DocumentAnalysis::where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes($timeoutMinutes))
            ->get();

        if ($stuckAnalyses->isEmpty()) {
            $this->info('✅ Nenhuma análise travada encontrada.');
            return Command::SUCCESS;
        }

        $this->warn("⚠️  Encontradas {$stuckAnalyses->count()} análises travadas:");

        // Exibe informações das análises
        foreach ($stuckAnalyses as $analysis) {
            $minutesAgo = now()->diffInMinutes($analysis->updated_at);
            $this->line("  • ID: {$analysis->id} | Processo: {$analysis->numero_processo} | Travada há {$minutesAgo} minutos");
        }

        if (!$this->confirm('Deseja marcar essas análises como "failed"?', true)) {
            $this->info('Operação cancelada.');
            return Command::SUCCESS;
        }

        // Atualiza as análises
        $updated = DocumentAnalysis::where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes($timeoutMinutes))
            ->update([
                'status' => 'failed',
                'error_message' => "Análise travada e marcada como falha automaticamente após {$timeoutMinutes} minutos (timeout)",
                'updated_at' => now()
            ]);

        $this->info("✅ Total de {$updated} análises marcadas como 'failed'.");

        return Command::SUCCESS;
    }
}
