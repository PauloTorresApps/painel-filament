<?php

namespace App\Pipeline\Graph;

use App\Models\DocumentAnalysis;

final class ProcessAnalysisGraphStateFactory
{
    public function fromDocumentAnalysis(DocumentAnalysis $analysis): GraphState
    {
        $jobParameters = is_array($analysis->job_parameters) ? $analysis->job_parameters : [];
        $storedState = is_array($analysis->graph_state) ? $analysis->graph_state : [];

        $base = [
            'analysis_id' => $analysis->id,
            'graph_name' => 'process_analysis',
            'ai_provider' => $jobParameters['ai_provider'] ?? $jobParameters['aiProvider'] ?? 'openrouter',
            'deep_thinking_enabled' => (bool) ($jobParameters['deep_thinking_enabled'] ?? $jobParameters['deepThinkingEnabled'] ?? false),
            'contexto_dados' => $jobParameters['contextoDados'] ?? [],
            'ai_model_id' => $jobParameters['ai_model_id'] ?? $jobParameters['aiModelId'] ?? null,
            'map_model_id' => $jobParameters['map_model_id'] ?? $jobParameters['mapModelId'] ?? null,
            'reduce_strategy' => $jobParameters['reduce_strategy'] ?? $jobParameters['reduceStrategy'] ?? 'auto',
            'prompt_template' => $jobParameters['promptTemplate'] ?? '',
            'user_id' => $analysis->user_id,
        ];

        return GraphState::fromArray(array_merge($base, $storedState));
    }
}
