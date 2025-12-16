<?php

namespace App\Console\Commands;

use App\Models\DocumentAnalysis;
use App\Jobs\AnalyzeProcessDocuments;
use Illuminate\Console\Command;

class RetryFailedAnalyses extends Command
{
    protected $signature = 'analysis:retry
                            {id? : ID específico da análise para reprocessar}
                            {--all : Reprocessar todas as análises que falharam}
                            {--limit=10 : Limite de análises a reprocessar (usado com --all)}';

    protected $description = 'Reprocessa análises de documentos que falharam';

    public function handle()
    {
        $analysisId = $this->argument('id');
        $all = $this->option('all');
        $limit = (int) $this->option('limit');

        // Caso 1: Reprocessar uma análise específica
        if ($analysisId) {
            return $this->retrySpecific($analysisId);
        }

        // Caso 2: Reprocessar todas as que falharam
        if ($all) {
            return $this->retryAll($limit);
        }

        // Caso 3: Listar análises que falharam
        return $this->listFailed();
    }

    protected function retrySpecific(int $analysisId): int
    {
        $analysis = DocumentAnalysis::find($analysisId);

        if (!$analysis) {
            $this->error("✗ Análise #{$analysisId} não encontrada");
            return 1;
        }

        if ($analysis->status !== 'failed') {
            $this->warn("⚠ Análise #{$analysisId} não está com status 'failed' (status atual: {$analysis->status})");

            if (!$this->confirm('Deseja reprocessar mesmo assim?')) {
                return 0;
            }
        }

        $this->info("Reprocessando análise #{$analysisId}...");
        $this->line("Processo: {$analysis->numero_processo}");
        $this->line("Erro anterior: {$analysis->error_message}");

        // Reseta status
        $analysis->update([
            'status' => 'pending',
            'error_message' => null,
            'ai_analysis' => null,
        ]);

        // Despacha o job
        AnalyzeProcessDocuments::dispatch($analysisId, $analysis->user_id);

        $this->info("✓ Job despachado para a fila");
        $this->line("Use 'php artisan queue:work' para processar");

        return 0;
    }

    protected function retryAll(int $limit): int
    {
        $failed = DocumentAnalysis::where('status', 'failed')
            ->limit($limit)
            ->get();

        if ($failed->isEmpty()) {
            $this->info("✓ Nenhuma análise com falha encontrada");
            return 0;
        }

        $this->warn("Encontradas {$failed->count()} análises com falha");

        if (!$this->confirm("Deseja reprocessar {$failed->count()} análise(s)?")) {
            return 0;
        }

        $bar = $this->output->createProgressBar($failed->count());
        $bar->start();

        foreach ($failed as $analysis) {
            $analysis->update([
                'status' => 'pending',
                'error_message' => null,
                'ai_analysis' => null,
            ]);

            AnalyzeProcessDocuments::dispatch($analysis->id, $analysis->user_id);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("✓ {$failed->count()} job(s) despachados para a fila");
        $this->line("Use 'php artisan queue:work' para processar");

        return 0;
    }

    protected function listFailed(): int
    {
        $failed = DocumentAnalysis::where('status', 'failed')
            ->orderBy('updated_at', 'desc')
            ->limit(50)
            ->get();

        if ($failed->isEmpty()) {
            $this->info("✓ Nenhuma análise com falha encontrada");
            return 0;
        }

        $this->warn("Encontradas {$failed->count()} análises com falha (últimas 50):");
        $this->newLine();

        $headers = ['ID', 'Processo', 'Data', 'Erro'];
        $rows = $failed->map(function ($analysis) {
            return [
                $analysis->id,
                $analysis->numero_processo,
                $analysis->updated_at->format('d/m/Y H:i'),
                $this->truncate($analysis->error_message, 60),
            ];
        })->toArray();

        $this->table($headers, $rows);

        $this->newLine();
        $this->line("Para reprocessar:");
        $this->info("  php artisan analysis:retry {id}        # Reprocessar uma específica");
        $this->info("  php artisan analysis:retry --all       # Reprocessar todas");

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
