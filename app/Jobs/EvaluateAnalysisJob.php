<?php

namespace App\Jobs;

use App\Evaluation\Contracts\MetricInterface;
use App\Evaluation\MetricResult;
use App\Evaluation\ScoreAggregator;
use App\Jobs\Middleware\OtelJobMiddleware;
use App\Models\AnalysisEvaluation;
use App\Models\DocumentAnalysis;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EvaluateAnalysisJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;
    public int $tries = 2;
    public int $backoff = 15;

    public function __construct(
        public int $documentAnalysisId
    ) {
    }

    public function middleware(): array
    {
        return [new OtelJobMiddleware()];
    }

    /**
     * Execute the job.
     */
    public function handle(ScoreAggregator $aggregator): void
    {
        if (!config('evaluation.enabled', true)) {
            return;
        }

        $analysis = DocumentAnalysis::with('microAnalyses')->find($this->documentAnalysisId);
        if (!$analysis || !$analysis->isCompleted()) {
            Log::info('EvaluateAnalysisJob: análise não encontrada ou não concluída', [
                'analysis_id' => $this->documentAnalysisId,
            ]);
            return;
        }

        $metricClasses = (array) config('evaluation.metrics', []);
        if (empty($metricClasses)) {
            Log::warning('EvaluateAnalysisJob: nenhuma métrica configurada', [
                'analysis_id' => $this->documentAnalysisId,
            ]);
            return;
        }

        $runId = (string) Str::uuid();
        $results = [];

        foreach ($metricClasses as $metricClass) {
            try {
                $metric = app($metricClass);
                if (!$metric instanceof MetricInterface) {
                    throw new \RuntimeException("{$metricClass} não implementa MetricInterface");
                }

                $result = $metric->evaluate($analysis);
            } catch (\Throwable $e) {
                $result = new MetricResult(
                    (string) $metricClass,
                    0.0,
                    1.0,
                    false,
                    ['error' => $e->getMessage()]
                );
            }

            $results[] = $result;

            AnalysisEvaluation::create([
                'document_analysis_id' => $analysis->id,
                'run_id' => $runId,
                'layer' => $result->layer,
                'metric_name' => $result->metricName,
                'score' => $result->score,
                'threshold' => $result->threshold,
                'passed' => $result->passed,
                'evidence' => $result->evidence,
            ]);
        }

        $summary = $aggregator->aggregate($results);
        Log::info('EvaluateAnalysisJob: avaliação concluída', [
            'analysis_id' => $analysis->id,
            'run_id' => $runId,
            'verdict' => $summary['verdict'] ?? 'REJECTED',
            'overall_score' => $summary['overall_score'] ?? 0,
            'failed_metrics' => $summary['failed_metrics'] ?? [],
        ]);
    }
}
