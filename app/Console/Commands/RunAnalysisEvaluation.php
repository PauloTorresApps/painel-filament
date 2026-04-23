<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateAnalysisJob;
use App\Models\DocumentAnalysis;
use Illuminate\Console\Command;

class RunAnalysisEvaluation extends Command
{
    protected $signature = 'evaluation:run
                            {analysis_id? : ID da análise para avaliar}
                            {--limit=20 : Quantidade máxima ao rodar em lote}';

    protected $description = 'Executa avaliação de qualidade para análises concluídas';

    public function handle(): int
    {
        $analysisId = $this->argument('analysis_id');
        $limit = max(1, (int) $this->option('limit'));

        if ($analysisId) {
            return $this->runSingle((int) $analysisId);
        }

        return $this->runBatch($limit);
    }

    private function runSingle(int $analysisId): int
    {
        $analysis = DocumentAnalysis::find($analysisId);

        if (!$analysis) {
            $this->error("Análise #{$analysisId} não encontrada.");
            return self::FAILURE;
        }

        if (!$analysis->isCompleted()) {
            $this->error("Análise #{$analysisId} ainda não está concluída.");
            return self::FAILURE;
        }

        EvaluateAnalysisJob::dispatchSync($analysis->id);

        $this->info("Avaliação executada para análise #{$analysis->id}.");
        return self::SUCCESS;
    }

    private function runBatch(int $limit): int
    {
        $analyses = DocumentAnalysis::query()
            ->where('status', 'completed')
            ->whereNotNull('ai_analysis')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        if ($analyses->isEmpty()) {
            $this->warn('Nenhuma análise concluída encontrada para avaliar.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($analyses->count());
        $bar->start();

        foreach ($analyses as $analysis) {
            EvaluateAnalysisJob::dispatchSync($analysis->id);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Avaliações executadas para {$analyses->count()} análise(s).");

        return self::SUCCESS;
    }
}
