<?php

namespace App\Console\Commands\ProcessAnalysis;

use App\Jobs\ProcessAnalysis\BuildChronologyJob;
use App\Jobs\ProcessAnalysis\BuildDesignerBriefJob;
use App\Jobs\ProcessAnalysis\BuildStructuredOpinionJob;
use App\Jobs\ProcessAnalysis\RunProcessEngineJob;
use App\Models\DocumentAnalysis;
use App\Models\ProcessAnalysis\ProcessActionPlanItem;
use App\Models\ProcessAnalysis\ProcessDeadline;
use App\Models\ProcessAnalysis\ProcessEngineSnapshot;
use App\Models\ProcessAnalysis\ProcessEvent;
use App\Models\ProcessAnalysis\ProcessInconsistency;
use App\Models\ProcessAnalysis\ProcessInertiaPeriod;
use App\Models\ProcessAnalysis\ProcessOpportunity;
use App\Models\ProcessAnalysis\ProcessRisk;
use App\Models\ProcessAnalysis\ProcessStructuredOpinion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReplayOwlexCommand extends Command
{
    protected $signature = 'analysis:replay-owlex
                            {analysis_id : ID da document_analysis}
                            {--queue=analysis : Fila para despacho dos jobs OWLEX}
                            {--force : Regera OWLEX do zero, limpando artefatos existentes}';

    protected $description = 'Retoma/reprocessa a cadeia OWLEX (chronology -> engine -> structured -> designer) para uma analise existente';

    public function handle(): int
    {
        $analysisId = (int) $this->argument('analysis_id');
        $queue = (string) $this->option('queue');
        $force = (bool) $this->option('force');

        $analysis = DocumentAnalysis::find($analysisId);

        if (!$analysis) {
            $this->error("DocumentAnalysis [{$analysisId}] nao encontrada.");
            return self::FAILURE;
        }

        if ($analysis->status === 'cancelled') {
            $this->error("DocumentAnalysis [{$analysisId}] esta cancelada e nao pode ser retomada.");
            return self::FAILURE;
        }

        $owlex = (array) (($analysis->analysis_ai_metadata ?? [])['owlex'] ?? []);

        $hasChronology = $analysis->processEvents()->exists();
        $hasEngineSnapshot = $analysis->engineSnapshots()->exists();
        $hasStructuredOpinion = $analysis->structuredOpinion()->exists();
        $hasDesignerBrief = !empty($owlex['finalized_at']) || !empty($owlex['designer_brief']);

        $this->table(
            ['Artifact', 'Status'],
            [
                ['chronology_events', $hasChronology ? 'present' : 'missing'],
                ['engine_snapshot', $hasEngineSnapshot ? 'present' : 'missing'],
                ['structured_opinion', $hasStructuredOpinion ? 'present' : 'missing'],
                ['designer_brief', $hasDesignerBrief ? 'present' : 'missing'],
            ]
        );

        if ($force) {
            $this->warn('Modo --force habilitado: limpando artefatos OWLEX para regeneracao completa.');
            $this->resetOwlexArtifacts($analysis);

            $analysis->update([
                'status' => 'processing',
                'current_phase' => DocumentAnalysis::PHASE_CHRONOLOGY,
                'is_resumable' => true,
                'progress_message' => 'Replay OWLEX forcado: reconstruindo cronologia...',
                'last_processed_at' => now(),
            ]);

            BuildChronologyJob::dispatch($analysis->id)->onQueue($queue);

            $this->info("Replay OWLEX forcado iniciado a partir de chronology para analysis_id={$analysis->id}.");
            return self::SUCCESS;
        }

        if ($hasDesignerBrief) {
            $this->info('OWLEX ja esta finalizado para esta analise. Nenhum replay necessario.');
            return self::SUCCESS;
        }

        if (!$hasChronology) {
            $analysis->update([
                'status' => 'processing',
                'current_phase' => DocumentAnalysis::PHASE_CHRONOLOGY,
                'is_resumable' => true,
                'progress_message' => 'Retomada manual OWLEX: reconstruindo cronologia...',
                'last_processed_at' => now(),
            ]);

            BuildChronologyJob::dispatch($analysis->id)->onQueue($queue);

            $this->info("Replay OWLEX iniciado a partir de chronology para analysis_id={$analysis->id}.");
            return self::SUCCESS;
        }

        if (!$hasEngineSnapshot) {
            $analysis->update([
                'status' => 'processing',
                'current_phase' => DocumentAnalysis::PHASE_ENGINE,
                'is_resumable' => true,
                'progress_message' => 'Retomada manual OWLEX: executando engine...',
                'last_processed_at' => now(),
            ]);

            RunProcessEngineJob::dispatch($analysis->id)->onQueue($queue);

            $this->info("Replay OWLEX iniciado a partir de engine para analysis_id={$analysis->id}.");
            return self::SUCCESS;
        }

        if (!$hasStructuredOpinion) {
            $analysis->update([
                'status' => 'processing',
                'current_phase' => DocumentAnalysis::PHASE_PARECER_STRUCTURED,
                'is_resumable' => true,
                'progress_message' => 'Retomada manual OWLEX: gerando parecer estruturado...',
                'last_processed_at' => now(),
            ]);

            BuildStructuredOpinionJob::dispatch($analysis->id)->onQueue($queue);

            $this->info("Replay OWLEX iniciado a partir de structured_opinion para analysis_id={$analysis->id}.");
            return self::SUCCESS;
        }

        $analysis->update([
            'status' => 'processing',
            'current_phase' => DocumentAnalysis::PHASE_DESIGN,
            'is_resumable' => true,
            'progress_message' => 'Retomada manual OWLEX: finalizando designer brief...',
            'last_processed_at' => now(),
        ]);

        BuildDesignerBriefJob::dispatch($analysis->id)->onQueue($queue);

        $this->info("Replay OWLEX iniciado a partir de designer para analysis_id={$analysis->id}.");

        return self::SUCCESS;
    }

    private function resetOwlexArtifacts(DocumentAnalysis $analysis): void
    {
        DB::transaction(function () use ($analysis): void {
            $analysisId = (int) $analysis->id;

            $structuredOpinionIds = ProcessStructuredOpinion::query()
                ->where('document_analysis_id', $analysisId)
                ->pluck('id')
                ->all();

            if (!empty($structuredOpinionIds)) {
                ProcessActionPlanItem::query()
                    ->whereIn('process_structured_opinion_id', $structuredOpinionIds)
                    ->delete();
            }

            ProcessStructuredOpinion::query()->where('document_analysis_id', $analysisId)->delete();
            ProcessEngineSnapshot::query()->where('document_analysis_id', $analysisId)->delete();
            ProcessDeadline::query()->where('document_analysis_id', $analysisId)->delete();
            ProcessRisk::query()->where('document_analysis_id', $analysisId)->delete();
            ProcessOpportunity::query()->where('document_analysis_id', $analysisId)->delete();
            ProcessInconsistency::query()->where('document_analysis_id', $analysisId)->delete();
            ProcessInertiaPeriod::query()->where('document_analysis_id', $analysisId)->delete();
            ProcessEvent::query()->where('document_analysis_id', $analysisId)->delete();

            $metadata = (array) ($analysis->analysis_ai_metadata ?? []);
            unset($metadata['owlex']);

            $analysis->update([
                'analysis_ai_metadata' => $metadata,
                'status' => 'processing',
                'is_resumable' => true,
                'last_processed_at' => now(),
            ]);
        });
    }
}
