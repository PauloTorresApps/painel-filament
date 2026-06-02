<?php

namespace App\Jobs\ProcessAnalysis;

use App\Jobs\Middleware\OtelJobMiddleware;
use App\Models\AiPrompt;
use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use App\Models\ProcessAnalysis\ProcessEvent;
use App\Models\ProcessAnalysis\ProcessInertiaPeriod;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class BuildChronologyJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;
    public int $tries;
    public int $backoff;

    public function __construct(public int $analysisId)
    {
        $this->timeout = config('analysis.jobs.build_chronology.timeout', 300);
        $this->tries = config('analysis.jobs.build_chronology.tries', 2);
        $this->backoff = config('analysis.jobs.build_chronology.backoff', 30);
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

        $eventsAlreadyBuilt = ProcessEvent::where('document_analysis_id', $analysis->id)->exists();

        if ($eventsAlreadyBuilt) {
            Log::info('BuildChronologyJob: Cronologia ja existente, pulando reprocessamento', [
                'analysis_id' => $analysis->id,
            ]);
            return;
        }

        $chronologyTemplate = AiPrompt::resolvePromptContent(
            1,
            AiPrompt::TYPE_CHRONOLOGY_BUILDER
        );

        $analysis->update([
            'current_phase' => DocumentAnalysis::PHASE_CHRONOLOGY,
            'progress_message' => 'Consolidando cronologia processual...',
            'last_processed_at' => now(),
        ]);

        ProcessEvent::where('document_analysis_id', $analysis->id)->delete();
        ProcessInertiaPeriod::where('document_analysis_id', $analysis->id)->delete();

        $rawEvents = [];

        $micros = $analysis->microAnalyses()
            ->where('reduce_level', 0)
            ->where('status', 'completed')
            ->orderBy('document_index')
            ->get();

        foreach ($micros as $micro) {
            $events = $micro->timeline_events['eventos'] ?? [];

            if (is_array($events) && !empty($events)) {
                foreach ($events as $event) {
                    $descricao = trim((string) ($event['descricao'] ?? 'Evento identificado durante analise documental.'));
                    if ($descricao === '') {
                        $descricao = $this->renderPromptSnippet($chronologyTemplate, [
                            ':evento' => (string) ($event['evento_ou_id'] ?? $micro->id_documento ?? 's/n'),
                            ':tipo' => (string) ($event['tipo'] ?? 'evento'),
                        ]);
                    }

                    $tipo = trim((string) ($event['tipo'] ?? 'evento'));

                    $rawEvents[] = [
                        'source_micro_analysis_id' => $micro->id,
                        'data' => $this->normalizeDate($event['data'] ?? null),
                        'evento_ou_id' => $event['evento_ou_id'] ?? $micro->id_documento,
                        'tipo_de_ato' => $tipo !== '' ? $tipo : 'evento',
                        'autor_do_ato' => $event['autor'] ?? null,
                        'resumo_objetivo' => $descricao,
                        'efeito_juridico' => $event['efeito_juridico'] ?? null,
                        'abriu_prazo' => $this->detectDeadlineOpen($tipo, $descricao),
                        'prazo_identificado' => $event['prazo'] ?? null,
                        'marco_relevante' => ($event['relevancia'] ?? 'media') === 'alta',
                    ];
                }

                continue;
            }

            $fallbackSummary = $this->fallbackSummary($micro);
            if ($fallbackSummary === '') {
                $fallbackSummary = $this->renderPromptSnippet($chronologyTemplate, [
                    ':evento' => (string) ($micro->id_documento ?? 's/n'),
                    ':tipo' => (string) ($micro->descricao ?? 'documento'),
                ]);
            }

            $rawEvents[] = [
                'source_micro_analysis_id' => $micro->id,
                'data' => null,
                'evento_ou_id' => $micro->id_documento,
                'tipo_de_ato' => $micro->descricao ?: 'documento',
                'autor_do_ato' => null,
                'resumo_objetivo' => $fallbackSummary,
                'efeito_juridico' => null,
                'abriu_prazo' => $this->detectDeadlineOpen((string) $micro->descricao, $fallbackSummary),
                'prazo_identificado' => null,
                'marco_relevante' => false,
            ];
        }

        usort($rawEvents, function (array $a, array $b): int {
            $dateA = $a['data'] ?? '9999-12-31';
            $dateB = $b['data'] ?? '9999-12-31';
            if ($dateA === $dateB) {
                return 0;
            }

            return $dateA < $dateB ? -1 : 1;
        });

        $savedEvents = [];
        foreach ($rawEvents as $index => $event) {
            $savedEvents[] = ProcessEvent::create([
                'document_analysis_id' => $analysis->id,
                'source_micro_analysis_id' => $event['source_micro_analysis_id'],
                'data' => $event['data'],
                'evento_ou_id' => $event['evento_ou_id'],
                'tipo_de_ato' => $event['tipo_de_ato'],
                'autor_do_ato' => $event['autor_do_ato'],
                'resumo_objetivo' => $event['resumo_objetivo'],
                'efeito_juridico' => $event['efeito_juridico'],
                'abriu_prazo' => $event['abriu_prazo'],
                'prazo_identificado' => $event['prazo_identificado'],
                'marco_relevante' => $event['marco_relevante'],
                'ordem' => $index + 1,
            ]);
        }

        $this->buildInertiaPeriods($analysis->id, $savedEvents, $chronologyTemplate);

        Log::info('BuildChronologyJob: Cronologia consolidada', [
            'analysis_id' => $analysis->id,
            'events' => count($savedEvents),
        ]);

        RunProcessEngineJob::dispatch($analysis->id)->onQueue('analysis');
    }

    private function buildInertiaPeriods(int $analysisId, array $events, string $chronologyTemplate): void
    {
        $inertiaGapDays = (int) config('analysis.owlex.chronology.inertia_gap_days', 60);

        $datedEvents = array_values(array_filter($events, fn (ProcessEvent $event) => !empty($event->data)));

        if (count($datedEvents) < 2) {
            return;
        }

        for ($i = 1; $i < count($datedEvents); $i++) {
            $previous = $datedEvents[$i - 1];
            $current = $datedEvents[$i];

            $start = Carbon::parse((string) $previous->data);
            $end = Carbon::parse((string) $current->data);
            $days = $start->diffInDays($end);

            if ($days <= $inertiaGapDays) {
                continue;
            }

            ProcessInertiaPeriod::create([
                'document_analysis_id' => $analysisId,
                'data_inicio' => $start->toDateString(),
                'data_fim' => $end->toDateString(),
                'responsavel_aparente' => 'indeterminado',
                'possivel_consequencia' => $this->renderPromptSnippet($chronologyTemplate, [
                    ':duracao_dias' => (string) $days,
                    ':data_inicio' => $start->toDateString(),
                    ':data_fim' => $end->toDateString(),
                ]),
                'duracao_dias' => $days,
            ]);
        }
    }

    private function normalizeDate(?string $date): ?string
    {
        if (empty($date)) {
            return null;
        }

        try {
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date)) {
                return Carbon::createFromFormat('d/m/Y', $date)->toDateString();
            }

            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function detectDeadlineOpen(string $tipo, string $descricao): bool
    {
        $haystack = mb_strtolower($tipo . ' ' . $descricao);

        return str_contains($haystack, 'prazo')
            || str_contains($haystack, 'intim')
            || str_contains($haystack, 'manifest')
            || str_contains($haystack, 'cumprir');
    }

    private function fallbackSummary(DocumentMicroAnalysis $micro): string
    {
        $analysisText = trim((string) $micro->micro_analysis);

        if ($analysisText === '') {
            return 'Documento processado sem resumo textual disponivel.';
        }

        return mb_substr($analysisText, 0, 500);
    }

    private function renderPromptSnippet(string $template, array $variables): string
    {
        $rendered = trim(strtr($template, $variables));

        if ($rendered === '' || $this->isRawPromptOutput($rendered)) {
            $event = (string) ($variables[':evento'] ?? 'evento nao identificado');
            $type = (string) ($variables[':tipo'] ?? 'ato processual');
            $days = (string) ($variables[':duracao_dias'] ?? '0');

            if ($days !== '0') {
                return "Periodo de inercia de {$days} dias identificado entre atos processuais relevantes.";
            }

            return "{$type} registrado sob referencia {$event}.";
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
            ':duracao_dias',
        ];

        foreach ($markers as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }
}
