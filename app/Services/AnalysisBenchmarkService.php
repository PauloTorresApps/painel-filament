<?php

namespace App\Services;

use App\Models\ContractAnalysis;
use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use Illuminate\Support\Collection;

class AnalysisBenchmarkService
{
    public function collect(int $days): array
    {
        $days = max(1, $days);
        $cutoff = now()->subDays($days);

        return [
            'window_days' => $days,
            'generated_at' => now()->toIso8601String(),
            'judicial' => $this->buildJudicialMetrics($cutoff),
            'contracts' => $this->buildContractMetrics($cutoff),
        ];
    }

    private function buildJudicialMetrics($cutoff): array
    {
        $analyses = DocumentAnalysis::query()
            ->where('status', 'completed')
            ->where('created_at', '>=', $cutoff)
            ->get(['id', 'total_documents', 'processing_time_ms']);

        if ($analyses->isEmpty()) {
            return [
                'completed_analyses' => 0,
                'avg_total_ms' => 0,
                'p95_total_ms' => 0,
                'docs_per_min' => 0.0,
                'map_micro_completed' => 0,
                'reduce_micro_completed' => 0,
                'avg_map_ms' => 0,
                'avg_reduce_ms' => 0,
                'map_tokens_total' => 0,
                'reduce_tokens_total' => 0,
                'tokens_total' => 0,
            ];
        }

        $analysisIds = $analyses->pluck('id');

        $microAnalyses = DocumentMicroAnalysis::query()
            ->whereIn('document_analysis_id', $analysisIds)
            ->where('status', 'completed')
            ->get(['reduce_level', 'token_count', 'processing_time_ms']);

        $mapMicros = $microAnalyses->where('reduce_level', 0);
        $reduceMicros = $microAnalyses->where('reduce_level', '>', 0);

        $totalDocuments = (int) $analyses->sum('total_documents');
        $totalTimeMs = (int) $analyses->sum('processing_time_ms');

        return [
            'completed_analyses' => $analyses->count(),
            'avg_total_ms' => (int) round($analyses->avg('processing_time_ms') ?? 0),
            'p95_total_ms' => $this->percentileInt($analyses->pluck('processing_time_ms')->filter(), 95),
            'docs_per_min' => $totalTimeMs > 0 ? round(($totalDocuments * 60000) / $totalTimeMs, 2) : 0.0,
            'map_micro_completed' => $mapMicros->count(),
            'reduce_micro_completed' => $reduceMicros->count(),
            'avg_map_ms' => (int) round($mapMicros->avg('processing_time_ms') ?? 0),
            'avg_reduce_ms' => (int) round($reduceMicros->avg('processing_time_ms') ?? 0),
            'map_tokens_total' => (int) $mapMicros->sum('token_count'),
            'reduce_tokens_total' => (int) $reduceMicros->sum('token_count'),
            'tokens_total' => (int) $microAnalyses->sum('token_count'),
        ];
    }

    private function buildContractMetrics($cutoff): array
    {
        $analyses = ContractAnalysis::query()
            ->where('status', ContractAnalysis::STATUS_COMPLETED)
            ->where('created_at', '>=', $cutoff)
            ->get([
                'processing_time_ms',
                'legal_opinion_processing_time_ms',
                'infographic_processing_time_ms',
                'analysis_ai_metadata',
                'legal_opinion_ai_metadata',
                'infographic_ai_metadata',
            ]);

        if ($analyses->isEmpty()) {
            return [
                'completed_analyses' => 0,
                'avg_analysis_ms' => 0,
                'avg_legal_opinion_ms' => 0,
                'avg_infographic_ms' => 0,
                'analysis_tokens_total' => 0,
                'legal_opinion_tokens_total' => 0,
                'infographic_tokens_total' => 0,
                'tokens_total' => 0,
            ];
        }

        $analysisTokens = 0;
        $legalTokens = 0;
        $infographicTokens = 0;

        foreach ($analyses as $analysis) {
            $analysisTokens += $this->extractTokens((array) ($analysis->analysis_ai_metadata ?? []));
            $legalTokens += $this->extractTokens((array) ($analysis->legal_opinion_ai_metadata ?? []));
            $infographicTokens += $this->extractTokens((array) ($analysis->infographic_ai_metadata ?? []));
        }

        return [
            'completed_analyses' => $analyses->count(),
            'avg_analysis_ms' => (int) round($analyses->avg('processing_time_ms') ?? 0),
            'avg_legal_opinion_ms' => (int) round($analyses->avg('legal_opinion_processing_time_ms') ?? 0),
            'avg_infographic_ms' => (int) round($analyses->avg('infographic_processing_time_ms') ?? 0),
            'analysis_tokens_total' => $analysisTokens,
            'legal_opinion_tokens_total' => $legalTokens,
            'infographic_tokens_total' => $infographicTokens,
            'tokens_total' => $analysisTokens + $legalTokens + $infographicTokens,
        ];
    }

    private function extractTokens(array $metadata): int
    {
        if (isset($metadata['total_tokens'])) {
            return (int) $metadata['total_tokens'];
        }

        if (isset($metadata['totals']['total_tokens'])) {
            return (int) $metadata['totals']['total_tokens'];
        }

        return 0;
    }

    private function percentileInt(Collection $values, int $percentile): int
    {
        if ($values->isEmpty()) {
            return 0;
        }

        $sorted = $values->map(fn ($v) => (int) $v)->sort()->values();
        $index = (int) ceil(($percentile / 100) * $sorted->count()) - 1;
        $index = max(0, min($index, $sorted->count() - 1));

        return (int) $sorted[$index];
    }
}
