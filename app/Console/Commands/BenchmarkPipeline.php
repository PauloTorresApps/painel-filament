<?php

namespace App\Console\Commands;

use App\Services\AnalysisBenchmarkService;
use Illuminate\Console\Command;

class BenchmarkPipeline extends Command
{
    protected $signature = 'analysis:benchmark-pipeline
                            {--days=7 : Janela de análise em dias}
                            {--format=table : Formato de saída (table|json)}';

    protected $description = 'Gera benchmark de latência e custo aproximado (tokens) dos pipelines judicial e contratos';

    public function handle(AnalysisBenchmarkService $benchmarkService): int
    {
        $days = max(1, (int) $this->option('days'));
        $format = strtolower((string) $this->option('format'));
        $report = $benchmarkService->collect($days);
        $judicial = $report['judicial'];
        $contracts = $report['contracts'];

        if ($format === 'json') {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $this->info("Benchmark de pipelines (últimos {$days} dia(s))");
        $this->newLine();

        $this->line('Pipeline Judicial (MAP/REDUCE)');
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Análises concluídas', (string) $judicial['completed_analyses']],
                ['Tempo médio total (ms)', (string) $judicial['avg_total_ms']],
                ['P95 tempo total (ms)', (string) $judicial['p95_total_ms']],
                ['Documentos por minuto', (string) $judicial['docs_per_min']],
                ['Micro MAP completas', (string) $judicial['map_micro_completed']],
                ['Micro REDUCE completas', (string) $judicial['reduce_micro_completed']],
                ['Tempo médio MAP (ms)', (string) $judicial['avg_map_ms']],
                ['Tempo médio REDUCE (ms)', (string) $judicial['avg_reduce_ms']],
                ['Tokens MAP', (string) $judicial['map_tokens_total']],
                ['Tokens REDUCE', (string) $judicial['reduce_tokens_total']],
                ['Tokens totais (aprox.)', (string) $judicial['tokens_total']],
            ]
        );

        $this->newLine();
        $this->line('Pipeline Contratos');
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Análises concluídas', (string) $contracts['completed_analyses']],
                ['Tempo médio análise (ms)', (string) $contracts['avg_analysis_ms']],
                ['Tempo médio parecer (ms)', (string) $contracts['avg_legal_opinion_ms']],
                ['Tempo médio infográfico (ms)', (string) $contracts['avg_infographic_ms']],
                ['Tokens análise', (string) $contracts['analysis_tokens_total']],
                ['Tokens parecer', (string) $contracts['legal_opinion_tokens_total']],
                ['Tokens infográfico', (string) $contracts['infographic_tokens_total']],
                ['Tokens totais (aprox.)', (string) $contracts['tokens_total']],
            ]
        );

        return self::SUCCESS;
    }
}
