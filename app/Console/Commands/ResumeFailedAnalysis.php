<?php

namespace App\Console\Commands;

use App\Jobs\ResumeAnalysisJob;
use App\Models\DocumentAnalysis;
use Illuminate\Console\Command;

class ResumeFailedAnalysis extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'analysis:resume
                            {id? : ID da análise a ser retomada}
                            {--all : Retomar todas as análises retomáveis}
                            {--user= : Retomar análises de um usuário específico}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retoma análises falhadas ou interrompidas do ponto onde pararam';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->resumeAll();
        }

        if ($this->option('user')) {
            return $this->resumeByUser((int) $this->option('user'));
        }

        $analysisId = $this->argument('id');

        if (!$analysisId) {
            $this->error('Forneça o ID da análise ou use --all para retomar todas');
            return Command::FAILURE;
        }

        return $this->resumeSingle((int) $analysisId);
    }

    /**
     * Retoma uma análise específica
     */
    private function resumeSingle(int $analysisId): int
    {
        $analysis = DocumentAnalysis::find($analysisId);

        if (!$analysis) {
            $this->error("Análise #{$analysisId} não encontrada");
            return Command::FAILURE;
        }

        if (!$analysis->canBeResumed()) {
            $this->warn("Análise #{$analysisId} não pode ser retomada");
            $this->info("Status: {$analysis->status}");
            $this->info("Progresso: {$analysis->processed_documents_count}/{$analysis->total_documents}");
            $this->info("Retomável: " . ($analysis->is_resumable ? 'Sim' : 'Não'));
            return Command::FAILURE;
        }

        $this->info("Retomando análise #{$analysisId}");
        $this->info("Processo: {$analysis->numero_processo}");
        $this->info("Progresso: {$analysis->processed_documents_count}/{$analysis->total_documents} documentos");
        $this->info("Percentual: {$analysis->getProgressPercentage()}%");

        ResumeAnalysisJob::dispatch($analysis->id);

        $this->info("✓ Job de retomada despachado com sucesso");

        return Command::SUCCESS;
    }

    /**
     * Retoma todas as análises retomáveis
     */
    private function resumeAll(): int
    {
        $analyses = DocumentAnalysis::where('status', 'failed')
            ->where('is_resumable', true)
            ->where('processed_documents_count', '<', DocumentAnalysis::raw('total_documents'))
            ->get();

        if ($analyses->isEmpty()) {
            $this->info('Nenhuma análise retomável encontrada');
            return Command::SUCCESS;
        }

        $this->info("Encontradas {$analyses->count()} análise(s) retomável(is)");

        foreach ($analyses as $analysis) {
            $this->line("- #{$analysis->id}: {$analysis->numero_processo} ({$analysis->getProgressPercentage()}%)");
        }

        if (!$this->confirm('Deseja retomar todas estas análises?', true)) {
            $this->info('Operação cancelada');
            return Command::SUCCESS;
        }

        $dispatched = 0;
        foreach ($analyses as $analysis) {
            ResumeAnalysisJob::dispatch($analysis->id);
            $dispatched++;
            $this->info("✓ Análise #{$analysis->id} despachada");
        }

        $this->info("✓ {$dispatched} job(s) despachado(s) com sucesso");

        return Command::SUCCESS;
    }

    /**
     * Retoma análises de um usuário específico
     */
    private function resumeByUser(int $userId): int
    {
        $analyses = DocumentAnalysis::where('user_id', $userId)
            ->where('status', 'failed')
            ->where('is_resumable', true)
            ->where('processed_documents_count', '<', DocumentAnalysis::raw('total_documents'))
            ->get();

        if ($analyses->isEmpty()) {
            $this->info("Nenhuma análise retomável encontrada para o usuário #{$userId}");
            return Command::SUCCESS;
        }

        $this->info("Encontradas {$analyses->count()} análise(s) retomável(is) para o usuário #{$userId}");

        foreach ($analyses as $analysis) {
            $this->line("- #{$analysis->id}: {$analysis->numero_processo} ({$analysis->getProgressPercentage()}%)");
        }

        if (!$this->confirm('Deseja retomar todas estas análises?', true)) {
            $this->info('Operação cancelada');
            return Command::SUCCESS;
        }

        $dispatched = 0;
        foreach ($analyses as $analysis) {
            ResumeAnalysisJob::dispatch($analysis->id);
            $dispatched++;
            $this->info("✓ Análise #{$analysis->id} despachada");
        }

        $this->info("✓ {$dispatched} job(s) despachado(s) com sucesso");

        return Command::SUCCESS;
    }
}
