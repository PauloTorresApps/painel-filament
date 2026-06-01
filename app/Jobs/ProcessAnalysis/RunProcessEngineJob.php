<?php

namespace App\Jobs\ProcessAnalysis;

use App\Jobs\Middleware\OtelJobMiddleware;
use App\Models\AiPrompt;
use App\Models\DocumentAnalysis;
use App\Models\ProcessAnalysis\ProcessDeadline;
use App\Models\ProcessAnalysis\ProcessEngineSnapshot;
use App\Models\ProcessAnalysis\ProcessInconsistency;
use App\Models\ProcessAnalysis\ProcessInertiaPeriod;
use App\Models\ProcessAnalysis\ProcessOpportunity;
use App\Models\ProcessAnalysis\ProcessPedido;
use App\Models\ProcessAnalysis\ProcessRisk;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunProcessEngineJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;
    public int $tries;
    public int $backoff;

    public function __construct(public int $analysisId)
    {
        $this->timeout = config('analysis.jobs.run_engine.timeout', 600);
        $this->tries = config('analysis.jobs.run_engine.tries', 2);
        $this->backoff = config('analysis.jobs.run_engine.backoff', 60);
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

        if (in_array($analysis->current_phase, [
            DocumentAnalysis::PHASE_PARECER_STRUCTURED,
            DocumentAnalysis::PHASE_DESIGN,
            DocumentAnalysis::PHASE_COMPLETED,
        ], true)) {
            Log::info('RunProcessEngineJob: Fase ja avancada, pulando reprocessamento', [
                'analysis_id' => $analysis->id,
                'current_phase' => $analysis->current_phase,
            ]);
            return;
        }

        $engineTemplate = AiPrompt::resolvePromptContent(
            1,
            AiPrompt::TYPE_ENGINE_INTELLIGENCE
        );

        $analysis->update([
            'current_phase' => DocumentAnalysis::PHASE_ENGINE,
            'progress_message' => 'Executando engine juridico de riscos e oportunidades...',
            'last_processed_at' => now(),
        ]);

        ProcessDeadline::where('document_analysis_id', $analysis->id)->delete();
        ProcessRisk::where('document_analysis_id', $analysis->id)->delete();
        ProcessOpportunity::where('document_analysis_id', $analysis->id)->delete();
        ProcessInconsistency::where('document_analysis_id', $analysis->id)->delete();

        $events = $analysis->processEvents()->orderBy('ordem')->get();
        $inertiaPeriods = ProcessInertiaPeriod::where('document_analysis_id', $analysis->id)->get();

        $this->createDeadlinesFromEvents($analysis->id, $events->all());
        $this->createRisks($analysis->id, $inertiaPeriods->all(), $engineTemplate);
        $this->createInconsistencies($analysis);
        $this->createOpportunities($analysis->id, $engineTemplate);

        $engineStats = $this->collectEngineStats($analysis->id);
        $scores = $this->calculateScores($engineStats);

        $situacaoAtual = [
            'total_eventos' => $events->count(),
            'total_prazos' => $engineStats['total_deadlines'],
            'total_riscos' => $engineStats['total_risks'],
            'total_oportunidades' => $engineStats['total_opportunities'],
            'total_inconsistencias' => $engineStats['total_inconsistencies'],
        ];

        $pendencias = ProcessDeadline::where('document_analysis_id', $analysis->id)
            ->where('status', 'pendente')
            ->orderBy('data_final_estimada')
            ->limit(10)
            ->get(['id', 'ato', 'data_final_estimada', 'status'])
            ->map(fn ($d) => $d->toArray())
            ->toArray();

        ProcessEngineSnapshot::create([
            'document_analysis_id' => $analysis->id,
            'risco_processual_score' => $scores['risk'],
            'urgencia_score' => $scores['urgency'],
            'oportunidade_score' => $scores['opportunity'],
            'confiabilidade_score' => $scores['confidence'],
            'situacao_atual' => $situacaoAtual,
            'pendencias' => $pendencias,
            'generated_at' => now(),
        ]);

        Log::info('RunProcessEngineJob: Snapshot gerado', [
            'analysis_id' => $analysis->id,
            'scores' => $scores,
        ]);

        BuildStructuredOpinionJob::dispatch($analysis->id)->onQueue('analysis');
    }

    private function createDeadlinesFromEvents(int $analysisId, array $events): void
    {
        $today = now()->startOfDay();

        foreach ($events as $event) {
            $hasDeadline = (bool) $event->abriu_prazo || !empty($event->prazo_identificado);
            if (!$hasDeadline) {
                continue;
            }

            $days = $this->extractDaysFromPrazo((string) $event->prazo_identificado);
            $marcoInicial = $event->data ? Carbon::parse((string) $event->data) : null;
            $dataFinal = ($marcoInicial && $days !== null) ? $marcoInicial->copy()->addDays($days) : null;

            $status = 'pendente';
            if ($dataFinal && $dataFinal->lt($today)) {
                $status = 'vencido';
            }

            ProcessDeadline::create([
                'document_analysis_id' => $analysisId,
                'process_event_id' => $event->id,
                'tipo' => 'prazo',
                'ato' => $event->tipo_de_ato ?: $event->resumo_objetivo,
                'marco_inicial' => $marcoInicial?->toDateString(),
                'regra_de_contagem' => $days ? 'contagem simples em dias corridos' : null,
                'prazo' => $event->prazo_identificado,
                'data_final_estimada' => $dataFinal?->toDateString(),
                'foi_cumprido' => null,
                'regime_juridico' => null,
                'atos_interruptivos' => null,
                'conclusao' => null,
                'status' => $status,
            ]);
        }
    }

    private function createRisks(int $analysisId, array $inertiaPeriods, string $engineTemplate): void
    {
        $overdueDeadlines = ProcessDeadline::where('document_analysis_id', $analysisId)
            ->where('status', 'vencido')
            ->get();

        foreach ($overdueDeadlines as $deadline) {
            ProcessRisk::create([
                'document_analysis_id' => $analysisId,
                'process_event_id' => $deadline->process_event_id,
                'descricao' => $this->renderPromptSnippet($engineTemplate, [
                    ':tipo' => 'prazo_vencido',
                    ':score' => '90',
                    ':contexto' => (string) ($deadline->ato ?? 'prazo sem descricao'),
                ]),
                'nivel' => 'alto',
                'categoria' => 'prazo',
                'impacto' => 5,
                'urgencia' => 5,
                'fundamento' => $deadline->ato,
                'score' => 90,
            ]);
        }

        foreach ($inertiaPeriods as $period) {
            $days = (int) ($period->duracao_dias ?? 0);
            if ($days <= 0) {
                continue;
            }

            $score = min(100, 40 + intdiv($days, 3));
            $nivel = $score >= 80 ? 'alto' : 'medio';

            ProcessRisk::create([
                'document_analysis_id' => $analysisId,
                'process_event_id' => null,
                'descricao' => $this->renderPromptSnippet($engineTemplate, [
                    ':tipo' => 'inercia_processual',
                    ':score' => (string) min(100, $score),
                    ':contexto' => (string) ($period->possivel_consequencia ?? 'inercia detectada'),
                ]),
                'nivel' => $nivel,
                'categoria' => 'inercia',
                'impacto' => $score >= 80 ? 4 : 3,
                'urgencia' => $score >= 80 ? 4 : 3,
                'fundamento' => $period->possivel_consequencia,
                'score' => min(100, $score),
            ]);
        }
    }

    private function createInconsistencies(DocumentAnalysis $analysis): void
    {
        $problematicInventory = $analysis->inventoryItems()
            ->where(function ($q) {
                $q->where('legivel', false)
                    ->orWhere('completo', false);
            })
            ->get();

        foreach ($problematicInventory as $item) {
            ProcessInconsistency::create([
                'document_analysis_id' => $analysis->id,
                'tipo' => 'qualidade_documental',
                'descricao' => $item->resumo ?: 'Item com baixa legibilidade ou incompleto.',
                'eventos_relacionados' => $item->evento_ou_id ? [(string) $item->evento_ou_id] : null,
                'possivel_consequencia' => 'Risco de diagnostico parcial por lacuna documental.',
                'score' => 70,
            ]);
        }
    }

    private function createOpportunities(int $analysisId, string $engineTemplate): void
    {
        $pedidos = ProcessPedido::where('document_analysis_id', $analysisId)
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereNotIn('status', ['deferido', 'indeferido']);
            })
            ->limit(5)
            ->get();

        foreach ($pedidos as $pedido) {
            ProcessOpportunity::create([
                'document_analysis_id' => $analysisId,
                'descricao' => $this->renderPromptSnippet($engineTemplate, [
                    ':tipo' => 'oportunidade_pedido_pendente',
                    ':score' => '85',
                    ':contexto' => (string) ($pedido->pedido ?? 'pedido pendente'),
                ]),
                'prioridade' => 'alta',
                'objetivo' => $pedido->pedido ?? 'Reforcar pedido pendente',
                'fundamento' => $pedido->fundamentacao,
                'ato_recomendado' => 'Peticionar atualizacao e requerer impulso oficial.',
                'score' => 85,
            ]);
        }

        if ($pedidos->isEmpty()) {
            ProcessOpportunity::create([
                'document_analysis_id' => $analysisId,
                'descricao' => $this->renderPromptSnippet($engineTemplate, [
                    ':tipo' => 'oportunidade_planejamento',
                    ':score' => '60',
                    ':contexto' => 'planejamento estrategico do caso',
                ]),
                'prioridade' => 'media',
                'objetivo' => 'Organizar proxima etapa estrategica',
                'fundamento' => 'Consolidacao dos eventos, riscos e prazos mapeados.',
                'ato_recomendado' => 'Preparar minuta com plano de acao de curto prazo.',
                'score' => 60,
            ]);
        }
    }

    private function collectEngineStats(int $analysisId): array
    {
        $riskStats = ProcessRisk::query()
            ->where('document_analysis_id', $analysisId)
            ->selectRaw('COUNT(*) as total_risks')
            ->selectRaw("SUM(CASE WHEN nivel = ? THEN 1 ELSE 0 END) as high_risks", ['alto'])
            ->first();

        $deadlineStats = ProcessDeadline::query()
            ->where('document_analysis_id', $analysisId)
            ->selectRaw('COUNT(*) as total_deadlines')
            ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_deadlines", ['pendente'])
            ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as overdue_deadlines", ['vencido'])
            ->first();

        return [
            'total_risks' => (int) ($riskStats?->total_risks ?? 0),
            'high_risks' => (int) ($riskStats?->high_risks ?? 0),
            'total_inconsistencies' => (int) ProcessInconsistency::where('document_analysis_id', $analysisId)->count(),
            'pending_deadlines' => (int) ($deadlineStats?->pending_deadlines ?? 0),
            'overdue_deadlines' => (int) ($deadlineStats?->overdue_deadlines ?? 0),
            'total_deadlines' => (int) ($deadlineStats?->total_deadlines ?? 0),
            'total_opportunities' => (int) ProcessOpportunity::where('document_analysis_id', $analysisId)->count(),
        ];
    }

    private function calculateScores(array $engineStats): array
    {
        $totalRisks = $engineStats['total_risks'];
        $highRisks = $engineStats['high_risks'];
        $inconsistencies = $engineStats['total_inconsistencies'];
        $pendingDeadlines = $engineStats['pending_deadlines'];
        $overdueDeadlines = $engineStats['overdue_deadlines'];
        $opportunities = $engineStats['total_opportunities'];

        $risk = min(100, ($totalRisks * 12) + ($overdueDeadlines * 20));
        $urgency = min(100, ($overdueDeadlines * 25) + ($pendingDeadlines * 8) + ($highRisks * 10));
        $opportunity = min(100, $opportunities * 20);
        $confidence = max(10, 100 - (($inconsistencies * 18) + ($highRisks * 8)));

        return [
            'risk' => $risk,
            'urgency' => $urgency,
            'opportunity' => $opportunity,
            'confidence' => $confidence,
        ];
    }

    private function extractDaysFromPrazo(string $prazo): ?int
    {
        if ($prazo === '') {
            return null;
        }

        if (preg_match('/(\d{1,3})\s*dias?/i', $prazo, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/^(\d{1,3})$/', trim($prazo), $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function renderPromptSnippet(string $template, array $variables): string
    {
        $rendered = trim(strtr($template, $variables));

        if ($rendered === '') {
            return 'Engine processual executado com consolidacao de risco e oportunidade.';
        }

        return mb_substr($rendered, 0, 500);
    }
}
