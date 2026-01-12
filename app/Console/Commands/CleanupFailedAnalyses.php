<?php

namespace App\Console\Commands;

use App\Models\DocumentAnalysis;
use Illuminate\Console\Command;

class CleanupFailedAnalyses extends Command
{
    protected $signature = 'analysis:cleanup
                            {--all : Remove todas as análises com falha}
                            {--older-than= : Remove apenas análises com falha mais antigas que X dias}
                            {--user= : Remove apenas análises de um usuário específico (ID)}';

    protected $description = 'Remove análises com falha do painel de controle';

    public function handle(): int
    {
        $all = $this->option('all');
        $olderThan = $this->option('older-than');
        $userId = $this->option('user');

        // Monta a query base
        $query = DocumentAnalysis::where('status', 'failed');

        // Filtro por usuário
        if ($userId) {
            $query->where('user_id', $userId);
        }

        // Filtro por data
        if ($olderThan) {
            $days = (int) $olderThan;
            $query->where('updated_at', '<', now()->subDays($days));
        }

        // Conta registros que serão removidos
        $count = $query->count();

        if ($count === 0) {
            $this->info('✓ Nenhuma análise com falha encontrada para remover');
            return 0;
        }

        // Mostra resumo
        $this->warn("Encontradas {$count} análise(s) com falha");

        if ($olderThan) {
            $this->line("Filtro: mais antigas que {$olderThan} dia(s)");
        }

        if ($userId) {
            $this->line("Filtro: usuário ID {$userId}");
        }

        // Lista algumas análises que serão removidas
        $preview = $query->limit(5)->get();
        $this->newLine();
        $this->line('Exemplos de análises que serão removidas:');

        $headers = ['ID', 'Processo', 'Data', 'Erro'];
        $rows = $preview->map(function ($analysis) {
            return [
                $analysis->id,
                $analysis->numero_processo,
                $analysis->updated_at->format('d/m/Y H:i'),
                $this->truncate($analysis->error_message, 50),
            ];
        })->toArray();

        $this->table($headers, $rows);

        if ($count > 5) {
            $this->line("... e mais " . ($count - 5) . " análise(s)");
        }

        // Confirmação
        if (!$all && !$this->confirm('Deseja realmente remover estas análises?', false)) {
            $this->info('Operação cancelada');
            return 0;
        }

        // Remove as análises
        $deleted = $query->delete();

        $this->newLine();
        $this->info("✓ {$deleted} análise(s) removida(s) com sucesso");

        return 0;
    }

    protected function truncate(?string $text, int $length): string
    {
        if (!$text) {
            return '-';
        }

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length - 3) . '...';
    }
}
