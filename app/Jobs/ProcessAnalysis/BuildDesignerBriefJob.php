<?php

namespace App\Jobs\ProcessAnalysis;

use App\Jobs\Middleware\OtelJobMiddleware;
use App\Mail\ProcessAnalysis\ProcessAnalysisCompleted;
use App\Models\AiPrompt;
use App\Models\DocumentAnalysis;
use App\Models\ProcessAnalysis\ProcessEngineSnapshot;
use App\Models\ProcessAnalysis\ProcessOpportunity;
use App\Models\ProcessAnalysis\ProcessRisk;
use App\Models\ProcessAnalysis\ProcessStructuredOpinion;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BuildDesignerBriefJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;
    public int $tries;
    public int $backoff;

    public function __construct(public int $analysisId)
    {
        $this->timeout = config('analysis.jobs.build_designer_brief.timeout', 180);
        $this->tries = config('analysis.jobs.build_designer_brief.tries', 2);
        $this->backoff = config('analysis.jobs.build_designer_brief.backoff', 30);
    }

    public function middleware(): array
    {
        return [new OtelJobMiddleware()];
    }

    public function handle(): void
    {
        $analysis = DocumentAnalysis::find($this->analysisId);

        if (!$analysis || $analysis->status === 'cancelled') {
            return;
        }

        $existingMetadata = (array) ($analysis->analysis_ai_metadata ?? []);
        $existingOwlex = (array) ($existingMetadata['owlex'] ?? []);

        if (
            ($analysis->status === 'completed' && $analysis->current_phase === DocumentAnalysis::PHASE_COMPLETED)
            || !empty($existingOwlex['finalized_at'])
        ) {
            Log::info('BuildDesignerBriefJob: Etapa final ja concluida, pulando reprocessamento', [
                'analysis_id' => $analysis->id,
            ]);
            return;
        }

        $designerTemplate = AiPrompt::resolvePromptContent(
            1,
            AiPrompt::TYPE_DESIGNER_BRIEF
        );

        $analysis->update([
            'current_phase' => DocumentAnalysis::PHASE_DESIGN,
            'progress_message' => 'Finalizando designer brief e consolidando resultado...',
            'last_processed_at' => now(),
        ]);

        $snapshot = ProcessEngineSnapshot::where('document_analysis_id', $analysis->id)
            ->latest('generated_at')
            ->first();

        $opinion = ProcessStructuredOpinion::where('document_analysis_id', $analysis->id)->first();

        $topRisks = ProcessRisk::where('document_analysis_id', $analysis->id)
            ->orderByDesc('score')
            ->limit(3)
            ->get(['descricao', 'nivel', 'score'])
            ->map(fn ($risk) => $risk->toArray())
            ->toArray();

        $topOpportunities = ProcessOpportunity::where('document_analysis_id', $analysis->id)
            ->orderByDesc('score')
            ->limit(3)
            ->get(['descricao', 'prioridade', 'score'])
            ->map(fn ($opportunity) => $opportunity->toArray())
            ->toArray();

        $designerBrief = [
            'headline' => $this->renderPromptSnippet($designerTemplate, [
                ':secao' => 'headline',
                ':numero_processo' => (string) $analysis->numero_processo,
                ':risk_score' => (string) ($snapshot?->risco_processual_score ?? 0),
                ':urgency_score' => (string) ($snapshot?->urgencia_score ?? 0),
            ]),
            'risk_score' => $snapshot?->risco_processual_score,
            'urgency_score' => $snapshot?->urgencia_score,
            'opportunity_score' => $snapshot?->oportunidade_score,
            'confidence_score' => $snapshot?->confiabilidade_score,
            'sumario' => $opinion?->sumario_executivo,
            'conclusao_estrategica' => $opinion?->conclusao_estrategica,
            'top_riscos' => $topRisks,
            'top_oportunidades' => $topOpportunities,
        ];

        $metadata = $existingMetadata;
        $metadata['owlex'] = [
            'generated_at' => now()->toIso8601String(),
            'finalized_at' => now()->toIso8601String(),
            'designer_brief' => $designerBrief,
        ];

        $analysis->update([
            'status' => 'completed',
            'current_phase' => DocumentAnalysis::PHASE_COMPLETED,
            'analysis_ai_metadata' => $metadata,
            'is_resumable' => false,
            'last_processed_at' => now(),
            'progress_message' => 'Analise concluida com sucesso!',
        ]);

        Log::info('BuildDesignerBriefJob: OWLEX finalizado', [
            'analysis_id' => $analysis->id,
        ]);

        if ($this->notifyUser($analysis, $metadata)) {
            $metadata['owlex']['notification_sent_at'] = now()->toIso8601String();
            $analysis->update([
                'analysis_ai_metadata' => $metadata,
                'last_processed_at' => now(),
            ]);
        }
    }

    private function notifyUser(DocumentAnalysis $analysis, array $metadata): bool
    {
        $owlex = (array) ($metadata['owlex'] ?? []);
        if (!empty($owlex['notification_sent_at'])) {
            return false;
        }

        $user = User::find($analysis->user_id);
        if (!$user) {
            return false;
        }

        try {
            $totalDocs = $analysis->total_documents ?? 0;
            $timeSeconds = round(($analysis->processing_time_ms ?? 0) / 1000, 2);

            NotificationService::success(
                $user,
                'Analise Concluida',
                "Analise de {$totalDocs} documento(s) do processo {$analysis->numero_processo} concluida com sucesso! Tempo total: {$timeSeconds}s"
            );

            if ($user->wantsEmailFor('process_analysis')) {
                Mail::to($user)->send(new ProcessAnalysisCompleted($analysis, $user));
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('BuildDesignerBriefJob: Falha ao notificar usuario', [
                'analysis_id' => $analysis->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function renderPromptSnippet(string $template, array $variables): string
    {
        $rendered = trim(strtr($template, $variables));

        if ($rendered === '' || $this->isRawPromptOutput($rendered)) {
            $risk = (string) ($variables[':risk_score'] ?? $variables[':risco_score'] ?? '0');
            $urgency = (string) ($variables[':urgency_score'] ?? $variables[':urgencia_score'] ?? '0');
            $process = (string) ($variables[':numero_processo'] ?? 'nao informado');

            return "Panorama do processo {$process}: risco {$risk}, urgencia {$urgency}. Priorizar execucao imediata dos itens criticos e monitorar prazos.";
        }

        return mb_substr($rendered, 0, 500);
    }

    private function isRawPromptOutput(string $text): bool
    {
        $trimmed = ltrim($text);
        $lower = mb_strtolower($text);

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            return true;
        }

        $markers = [
            '"role"',
            '"inputs"',
            '"function"',
            '"objectives"',
            'template para',
            'deve aceitar placeholders',
            'sua função é',
            'sua funcao e',
            ':secao',
            ':tipo',
            ':score',
            ':contexto',
            ':numero_processo',
        ];

        foreach ($markers as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }
}
