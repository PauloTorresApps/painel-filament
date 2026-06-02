<?php

namespace App\Jobs\ProcessAnalysis;

use App\Jobs\Middleware\OtelJobMiddleware;
use App\Models\AiPrompt;
use App\Models\DocumentAnalysis;
use App\Models\ProcessAnalysis\ProcessActionPlanItem;
use App\Models\ProcessAnalysis\ProcessDeadline;
use App\Models\ProcessAnalysis\ProcessEngineSnapshot;
use App\Models\ProcessAnalysis\ProcessInconsistency;
use App\Models\ProcessAnalysis\ProcessOpportunity;
use App\Models\ProcessAnalysis\ProcessRisk;
use App\Models\ProcessAnalysis\ProcessStructuredOpinion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class BuildStructuredOpinionJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;
    public int $tries;
    public int $backoff;

    public function __construct(public int $analysisId)
    {
        $this->timeout = config('analysis.jobs.build_structured_parecer.timeout', 600);
        $this->tries = config('analysis.jobs.build_structured_parecer.tries', 2);
        $this->backoff = config('analysis.jobs.build_structured_parecer.backoff', 60);
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

        $structuredOpinionAlreadyBuilt = ProcessStructuredOpinion::where('document_analysis_id', $analysis->id)->exists();

        if ($structuredOpinionAlreadyBuilt) {
            Log::info('BuildStructuredOpinionJob: Parecer estruturado ja existente, pulando reprocessamento', [
                'analysis_id' => $analysis->id,
            ]);
            return;
        }

        $structuredTemplate = AiPrompt::resolvePromptContent(
            1,
            AiPrompt::TYPE_PARECER_STRUCTURED
        );

        $analysis->update([
            'current_phase' => DocumentAnalysis::PHASE_PARECER_STRUCTURED,
            'progress_message' => 'Montando parecer juridico estruturado...',
            'last_processed_at' => now(),
        ]);

        $snapshot = ProcessEngineSnapshot::where('document_analysis_id', $analysis->id)
            ->latest('generated_at')
            ->first();

        $risks = ProcessRisk::where('document_analysis_id', $analysis->id)
            ->orderByDesc('score')
            ->limit(8)
            ->get();

        $opportunities = ProcessOpportunity::where('document_analysis_id', $analysis->id)
            ->orderByDesc('score')
            ->limit(8)
            ->get();

        $deadlines = ProcessDeadline::where('document_analysis_id', $analysis->id)
            ->orderBy('data_final_estimada')
            ->limit(8)
            ->get();

        $inconsistencies = ProcessInconsistency::where('document_analysis_id', $analysis->id)
            ->orderByDesc('score')
            ->limit(8)
            ->get();

        $sumario = $this->buildSummary($analysis, $snapshot, $risks, $opportunities, $structuredTemplate);

        $opinion = ProcessStructuredOpinion::updateOrCreate(
            ['document_analysis_id' => $analysis->id],
            [
                'sumario_executivo' => $sumario,
                'diagnostico' => $this->buildDiagnostic($snapshot, $structuredTemplate),
                'prazos_preclusoes' => $this->buildDeadlinesText($deadlines),
                'prescricao_decadencia' => 'Nao foi detectado gatilho conclusivo automatizado para prescricao/decadencia. Recomendado revisar marcos interruptivos manualmente.',
                'inconsistencias_atencao' => $this->buildInconsistenciesText($inconsistencies),
                'riscos_priorizados' => $risks->map(fn ($risk) => [
                    'descricao' => $risk->descricao,
                    'nivel' => $risk->nivel,
                    'score' => $risk->score,
                    'fundamento' => $risk->fundamento,
                ])->toArray(),
                'oportunidades' => $opportunities->map(fn ($op) => [
                    'descricao' => $op->descricao,
                    'prioridade' => $op->prioridade,
                    'ato_recomendado' => $op->ato_recomendado,
                    'score' => $op->score,
                ])->toArray(),
                'conclusao_estrategica' => $this->buildConclusion($snapshot, $risks->count(), $opportunities->count(), $structuredTemplate),
            ]
        );

        ProcessActionPlanItem::where('process_structured_opinion_id', $opinion->id)->delete();
        $this->createActionPlan($opinion->id, $deadlines, $risks, $opportunities);

        Log::info('BuildStructuredOpinionJob: Parecer estruturado gerado', [
            'analysis_id' => $analysis->id,
            'structured_opinion_id' => $opinion->id,
        ]);

        BuildDesignerBriefJob::dispatch($analysis->id)->onQueue('analysis');
    }

    private function buildSummary(
        DocumentAnalysis $analysis,
        ?ProcessEngineSnapshot $snapshot,
        $risks,
        $opportunities,
        string $structuredTemplate
    ): string {
        $riskScore = $snapshot?->risco_processual_score ?? 0;
        $urgencyScore = $snapshot?->urgencia_score ?? 0;

        return $this->renderPromptSnippet($structuredTemplate, [
            ':secao' => 'sumario_executivo',
            ':numero_processo' => (string) $analysis->numero_processo,
            ':risco_score' => (string) $riskScore,
            ':urgencia_score' => (string) $urgencyScore,
            ':riscos_count' => (string) $risks->count(),
            ':oportunidades_count' => (string) $opportunities->count(),
        ]);
    }

    private function buildDiagnostic(?ProcessEngineSnapshot $snapshot, string $structuredTemplate): string
    {
        if (!$snapshot) {
            return 'Engine juridico sem snapshot disponivel para diagnostico consolidado.';
        }

        return $this->renderPromptSnippet($structuredTemplate, [
            ':secao' => 'diagnostico',
            ':risco_score' => (string) ((int) ($snapshot->risco_processual_score ?? 0)),
            ':urgencia_score' => (string) ((int) ($snapshot->urgencia_score ?? 0)),
            ':oportunidade_score' => (string) ((int) ($snapshot->oportunidade_score ?? 0)),
            ':confiabilidade_score' => (string) ((int) ($snapshot->confiabilidade_score ?? 0)),
        ]);
    }

    private function buildDeadlinesText($deadlines): string
    {
        if ($deadlines->isEmpty()) {
            return 'Nenhum prazo objetivo foi detectado automaticamente.';
        }

        $lines = [];
        foreach ($deadlines as $deadline) {
            $due = $deadline->data_final_estimada ? $deadline->data_final_estimada->format('d/m/Y') : 'sem data estimada';
            $lines[] = "- {$deadline->ato} | vencimento estimado: {$due} | status: {$deadline->status}";
        }

        return implode("\n", $lines);
    }

    private function buildInconsistenciesText($inconsistencies): string
    {
        if ($inconsistencies->isEmpty()) {
            return 'Nao foram detectadas inconsistencias materiais automaticas na base analisada.';
        }

        $lines = [];
        foreach ($inconsistencies as $item) {
            $lines[] = "- {$item->descricao}";
        }

        return implode("\n", $lines);
    }

    private function buildConclusion(?ProcessEngineSnapshot $snapshot, int $riskCount, int $opportunityCount, string $structuredTemplate): string
    {
        $confidence = (int) ($snapshot?->confiabilidade_score ?? 0);
        $scenario = $riskCount > $opportunityCount
            ? 'mitigacao_imediata'
            : 'proatividade_controlada';

        return $this->renderPromptSnippet($structuredTemplate, [
            ':secao' => 'conclusao_estrategica',
            ':cenario' => $scenario,
            ':confiabilidade_score' => (string) $confidence,
            ':riscos_count' => (string) $riskCount,
            ':oportunidades_count' => (string) $opportunityCount,
        ]);
    }

    private function createActionPlan($opinionId, $deadlines, $risks, $opportunities): void
    {
        $order = 1;

        foreach ($deadlines as $deadline) {
            ProcessActionPlanItem::create([
                'process_structured_opinion_id' => $opinionId,
                'prioridade' => $deadline->status === 'vencido' ? 'alta' : 'media',
                'ato_recomendado' => 'Validar cumprimento e, se necessario, peticionar regularizacao do prazo.',
                'objetivo' => $deadline->ato,
                'fundamento' => $deadline->conclusao,
                'prazo' => $deadline->data_final_estimada?->format('d/m/Y') ?? $deadline->prazo,
                'urgencia' => $deadline->status === 'vencido' ? 5 : 3,
                'risco_de_nao_agir' => $deadline->status === 'vencido' ? 5 : 3,
                'documentos_necessarios' => null,
                'grau_confianca' => 70,
                'ordem' => $order++,
            ]);
        }

        foreach ($risks as $risk) {
            ProcessActionPlanItem::create([
                'process_structured_opinion_id' => $opinionId,
                'prioridade' => $risk->nivel === 'alto' ? 'alta' : 'media',
                'ato_recomendado' => 'Mitigar risco com ato processual especifico e prova de suporte.',
                'objetivo' => $risk->descricao,
                'fundamento' => $risk->fundamento,
                'prazo' => 'imediato',
                'urgencia' => (int) ($risk->urgencia ?? 3),
                'risco_de_nao_agir' => min(5, max(1, intdiv((int) ($risk->score ?? 50), 20))),
                'documentos_necessarios' => null,
                'grau_confianca' => 65,
                'ordem' => $order++,
            ]);
        }

        foreach ($opportunities as $opportunity) {
            ProcessActionPlanItem::create([
                'process_structured_opinion_id' => $opinionId,
                'prioridade' => $opportunity->prioridade ?? 'media',
                'ato_recomendado' => $opportunity->ato_recomendado ?: 'Executar ato estrategico para capturar oportunidade.',
                'objetivo' => $opportunity->objetivo ?: $opportunity->descricao,
                'fundamento' => $opportunity->fundamento,
                'prazo' => 'curto prazo',
                'urgencia' => 3,
                'risco_de_nao_agir' => 2,
                'documentos_necessarios' => null,
                'grau_confianca' => 60,
                'ordem' => $order++,
            ]);
        }
    }

    private function renderPromptSnippet(string $template, array $variables): string
    {
        $rendered = trim(strtr($template, $variables));

        if ($rendered === '' || $this->isRawPromptOutput($rendered)) {
            $section = (string) ($variables[':secao'] ?? 'secao');
            $risk = (string) ($variables[':risco_score'] ?? '0');
            $urgency = (string) ($variables[':urgencia_score'] ?? '0');
            $opportunity = (string) ($variables[':oportunidade_score'] ?? '0');
            $confidence = (string) ($variables[':confiabilidade_score'] ?? '0');

            return "Secao {$section} consolidada com base nos indicadores do processo (risco {$risk}, urgencia {$urgency}, oportunidade {$opportunity}, confiabilidade {$confidence}).";
        }

        return mb_substr($rendered, 0, 3000);
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
