<?php

namespace App\Models;

use App\Models\ProcessAnalysis\ProcessActionPlanItem;
use App\Models\ProcessAnalysis\ProcessDeadline;
use App\Models\ProcessAnalysis\ProcessEngineSnapshot;
use App\Models\ProcessAnalysis\ProcessIntimacao;
use App\Models\ProcessAnalysis\ProcessEvent;
use App\Models\ProcessAnalysis\ProcessInconsistency;
use App\Models\ProcessAnalysis\ProcessInertiaPeriod;
use App\Models\ProcessAnalysis\ProcessInventoryItem;
use App\Models\ProcessAnalysis\ProcessDecisao;
use App\Models\ProcessAnalysis\ProcessOpportunity;
use App\Models\ProcessAnalysis\ProcessPedido;
use App\Models\ProcessAnalysis\ProcessRisk;
use App\Models\ProcessAnalysis\ProcessStructuredOpinion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

class DocumentAnalysis extends Model
{
    protected $fillable = [
        'user_id',
        'numero_processo',
        'parte_representada',
        'papel_processual',
        'objetivo_analise',
        'prazo_em_curso',
        'classe_processual',
        'assuntos',
        'id_documento',
        'descricao_documento',
        'extracted_text',
        'ai_analysis',
        'status',
        'error_message',
        'total_characters',
        'processing_time_ms',
        'job_parameters',
        // Campos para map-reduce (reutilizando campos evolutivos)
        'evolutionary_summary', // Não usado no map-reduce, mantido para compatibilidade
        'current_document_index',
        'processed_documents_count',
        'total_documents',
        'last_processed_at',
        'is_resumable',
        // Campos para tracking de fases
        'current_phase', // download, map, reduce, completed
        'reduce_current_level',
        'reduce_total_levels',
        'reduce_processed_batches',
        'reduce_total_batches',
        'progress_message',
        'analysis_ai_metadata',
        'langfuse_trace_id',
        'langfuse_session_id',
        'graph_run_id',
        'graph_last_node',
        'graph_state',
    ];

    protected $casts = [
        'total_characters' => 'integer',
        'processing_time_ms' => 'integer',
        'job_parameters' => 'array',
        'current_document_index' => 'integer',
        'processed_documents_count' => 'integer',
        'total_documents' => 'integer',
        'last_processed_at' => 'datetime',
        'is_resumable' => 'boolean',
        'reduce_current_level' => 'integer',
        'reduce_total_levels' => 'integer',
        'reduce_processed_batches' => 'integer',
        'reduce_total_batches' => 'integer',
        'analysis_ai_metadata' => 'array',
        'graph_state' => 'array',
    ];

    /**
     * Garante IDs estáveis de correlação para Langfuse/OTEL.
     */
    public function ensureLangfuseContext(): array
    {
        $updates = [];

        if (empty($this->langfuse_trace_id)) {
            $updates['langfuse_trace_id'] = (string) Str::uuid();
        }

        if (empty($this->langfuse_session_id)) {
            $updates['langfuse_session_id'] = (string) Str::uuid();
        }

        if (!empty($updates)) {
            $this->forceFill($updates)->save();
            $this->refresh();
        }

        return [
            'trace_id' => (string) $this->langfuse_trace_id,
            'session_id' => (string) $this->langfuse_session_id,
        ];
    }

    /**
     * Constantes para fases de processamento
     */
    public const PHASE_DOWNLOAD = 'download';
    public const PHASE_INVENTORY = 'inventory';
    public const PHASE_MAP = 'map';
    public const PHASE_REDUCE = 'reduce';
    public const PHASE_CHRONOLOGY = 'chronology';
    public const PHASE_ENGINE = 'engine';
    public const PHASE_PARECER_STRUCTURED = 'parecer_structured';
    public const PHASE_DESIGN = 'design';
    public const PHASE_COMPLETED = 'completed';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relacionamento com micro-análises (map-reduce)
     */
    public function microAnalyses(): HasMany
    {
        return $this->hasMany(DocumentMicroAnalysis::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(ProcessInventoryItem::class);
    }

    public function processEvents(): HasMany
    {
        return $this->hasMany(ProcessEvent::class);
    }

    public function inertiaPeriods(): HasMany
    {
        return $this->hasMany(ProcessInertiaPeriod::class);
    }

    public function processDeadlines(): HasMany
    {
        return $this->hasMany(ProcessDeadline::class);
    }

    public function processRisks(): HasMany
    {
        return $this->hasMany(ProcessRisk::class);
    }

    public function processOpportunities(): HasMany
    {
        return $this->hasMany(ProcessOpportunity::class);
    }

    public function processInconsistencies(): HasMany
    {
        return $this->hasMany(ProcessInconsistency::class);
    }

    public function processPedidos(): HasMany
    {
        return $this->hasMany(ProcessPedido::class);
    }

    public function processDecisoes(): HasMany
    {
        return $this->hasMany(ProcessDecisao::class);
    }

    public function processIntimacoes(): HasMany
    {
        return $this->hasMany(ProcessIntimacao::class);
    }

    public function engineSnapshots(): HasMany
    {
        return $this->hasMany(ProcessEngineSnapshot::class);
    }

    public function latestEngineSnapshot(): HasOne
    {
        return $this->hasOne(ProcessEngineSnapshot::class)->latestOfMany('generated_at');
    }

    public function structuredOpinion(): HasOne
    {
        return $this->hasOne(ProcessStructuredOpinion::class);
    }

    public function actionPlanItems(): HasManyThrough
    {
        return $this->hasManyThrough(
            ProcessActionPlanItem::class,
            ProcessStructuredOpinion::class,
            'document_analysis_id',
            'process_structured_opinion_id'
        );
    }

    /**
     * Verifica se a análise está completa
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Verifica se a análise falhou
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Verifica se a análise está em processamento
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Verifica se a análise foi cancelada
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Marca como processando
     */
    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    /**
     * Marca como completo
     */
    public function markAsCompleted(string $analysis, int $processingTime): void
    {
        $this->update([
            'status' => 'completed',
            'ai_analysis' => $analysis,
            'processing_time_ms' => $processingTime,
        ]);
    }

    /**
     * Marca como falho
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Inicializa análise com map-reduce
     */
    public function initializeMapReduce(int $totalDocuments): void
    {
        $this->update([
            'status' => 'processing',
            'current_phase' => self::PHASE_DOWNLOAD,
            'total_documents' => $totalDocuments,
            'current_document_index' => 0,
            'processed_documents_count' => 0,
            'is_resumable' => true,
            'last_processed_at' => now(),
            'progress_message' => "Baixando {$totalDocuments} documento(s)...",
        ]);
    }

    /**
     * Atualiza para fase MAP
     */
    public function startMapPhase(): void
    {
        $this->update([
            'current_phase' => self::PHASE_MAP,
            'progress_message' => "Analisando documentos individualmente (0/{$this->total_documents})...",
            'last_processed_at' => now(),
        ]);
    }

    /**
     * Atualiza progresso da fase MAP
     */
    public function updateMapProgress(int $completed): void
    {
        // Garante que o valor nunca ultrapasse total_documents
        $completed = min($completed, $this->total_documents ?? $completed);

        $this->update([
            'processed_documents_count' => $completed,
            'progress_message' => "Analisando documentos individualmente ({$completed}/{$this->total_documents})...",
            'last_processed_at' => now(),
        ]);
    }

    /**
     * Atualiza para fase REDUCE
     */
    public function startReducePhase(int $totalLevels, int $totalBatches): void
    {
        $this->update([
            'current_phase' => self::PHASE_REDUCE,
            'reduce_current_level' => 1,
            'reduce_total_levels' => $totalLevels,
            'reduce_processed_batches' => 0,
            'reduce_total_batches' => $totalBatches,
            'progress_message' => "Consolidando análises (Nível 1/{$totalLevels})...",
            'last_processed_at' => now(),
        ]);
    }

    /**
     * Atualiza progresso da fase REDUCE
     */
    public function updateReduceProgress(int $level, int $processedBatches, int $totalBatches): void
    {
        // Garante que processedBatches nunca ultrapasse totalBatches
        $processedBatches = min($processedBatches, $totalBatches);

        $this->update([
            'reduce_current_level' => $level,
            'reduce_processed_batches' => $processedBatches,
            'reduce_total_batches' => $totalBatches,
            'progress_message' => "Consolidando análises (Nível {$level}/{$this->reduce_total_levels}, Lote {$processedBatches}/{$totalBatches})...",
            'last_processed_at' => now(),
        ]);
    }

    /**
     * Atualiza para análise final
     */
    public function startFinalAnalysis(): void
    {
        $this->update([
            'progress_message' => "Gerando análise final consolidada...",
            'last_processed_at' => now(),
        ]);
    }

    /**
     * Retorna a fase atual formatada para exibição
     */
    public function getCurrentPhaseLabel(): string
    {
        return match ($this->current_phase) {
            self::PHASE_DOWNLOAD => 'Download',
            self::PHASE_INVENTORY => 'Inventário',
            self::PHASE_MAP => 'Análise Individual',
            self::PHASE_REDUCE => 'Consolidação',
            self::PHASE_CHRONOLOGY => 'Cronologia',
            self::PHASE_ENGINE => 'Engine',
            self::PHASE_PARECER_STRUCTURED => 'Parecer Estruturado',
            self::PHASE_DESIGN => 'Designer',
            self::PHASE_COMPLETED => 'Concluído',
            default => 'Processando',
        };
    }

    /**
     * Retorna o progresso geral como porcentagem (0-100)
     * Download: 0-10%, Inventário: 10-20%, MAP: 20-55%, REDUCE: 55-75%,
     * Cronologia: 75-83%, Engine: 83-91%, Parecer estruturado: 91-97%,
     * Designer: 97-100%
     */
    public function getOverallProgressPercentage(): float
    {
        $progress = match ($this->current_phase) {
            self::PHASE_DOWNLOAD => min(10, $this->getProgressPercentage() * 0.1),
            self::PHASE_INVENTORY => 10 + min(10, $this->getProgressPercentage() * 0.1),
            self::PHASE_MAP => 20 + ($this->getProgressPercentage() * 0.35),
            self::PHASE_REDUCE => 55 + ($this->getReduceProgressPercentage() * 0.2),
            self::PHASE_CHRONOLOGY => 83,
            self::PHASE_ENGINE => 91,
            self::PHASE_PARECER_STRUCTURED => 97,
            self::PHASE_DESIGN => 99,
            self::PHASE_COMPLETED => 100,
            default => 0,
        };

        return min(100, $progress);
    }

    /**
     * Retorna o progresso da fase REDUCE como porcentagem
     */
    public function getReduceProgressPercentage(): float
    {
        if ($this->reduce_total_batches === 0) {
            return 0;
        }

        $processed = max(0, $this->reduce_processed_batches ?? 0);

        return min(100, round(($processed / $this->reduce_total_batches) * 100, 2));
    }

    /**
     * Verifica se está na fase REDUCE
     */
    public function isInReducePhase(): bool
    {
        return $this->current_phase === self::PHASE_REDUCE;
    }

    /**
     * Retorna progresso percentual
     */
    public function getProgressPercentage(): float
    {
        if ($this->total_documents === 0) {
            return 0;
        }

        // Garante que o valor nunca seja negativo
        $processed = max(0, $this->processed_documents_count ?? 0);

        return min(100, round(($processed / $this->total_documents) * 100, 2));
    }

    /**
     * Retorna contagem de micro-análises por status
     */
    public function getMicroAnalysisStats(): array
    {
        return [
            'total' => $this->microAnalyses()->count(),
            'pending' => $this->microAnalyses()->pending()->count(),
            'processing' => $this->microAnalyses()->where('status', 'processing')->count(),
            'completed' => $this->microAnalyses()->completed()->count(),
            'failed' => $this->microAnalyses()->failed()->count(),
            'map_completed' => $this->microAnalyses()->mapLevel()->completed()->count(),
            'reduce_completed' => $this->microAnalyses()->where('reduce_level', '>', 0)->completed()->count(),
        ];
    }

    /**
     * Verifica se a fase MAP está completa
     */
    public function isMapPhaseComplete(): bool
    {
        $mapTotal = $this->microAnalyses()->mapLevel()->count();
        $mapDone = $this->microAnalyses()->mapLevel()
            ->whereIn('status', ['completed', 'failed'])
            ->count();

        return $mapTotal > 0 && $mapDone >= $mapTotal;
    }

    /**
     * Verifica se pode ser retomada (para map-reduce)
     */
    public function canBeResumed(): bool
    {
        if (!$this->is_resumable) {
            return false;
        }

        if (!in_array($this->status, ['processing', 'failed'])) {
            return false;
        }

        // Verifica se há micro-análises pendentes
        return $this->microAnalyses()->pending()->exists();
    }
}
