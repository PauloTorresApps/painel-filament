<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentAnalysis extends Model
{
    protected $fillable = [
        'user_id',
        'numero_processo',
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
    ];

    /**
     * Constantes para fases de processamento
     */
    public const PHASE_DOWNLOAD = 'download';
    public const PHASE_MAP = 'map';
    public const PHASE_REDUCE = 'reduce';
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
            self::PHASE_MAP => 'Análise Individual',
            self::PHASE_REDUCE => 'Consolidação',
            self::PHASE_COMPLETED => 'Concluído',
            default => 'Processando',
        };
    }

    /**
     * Retorna o progresso geral como porcentagem (0-100)
     * Download: 0-10%, MAP: 10-70%, REDUCE: 70-100%
     */
    public function getOverallProgressPercentage(): float
    {
        return match ($this->current_phase) {
            self::PHASE_DOWNLOAD => min(10, $this->getProgressPercentage() * 0.1),
            self::PHASE_MAP => 10 + ($this->getProgressPercentage() * 0.6),
            self::PHASE_REDUCE => 70 + ($this->getReduceProgressPercentage() * 0.3),
            self::PHASE_COMPLETED => 100,
            default => 0,
        };
    }

    /**
     * Retorna o progresso da fase REDUCE como porcentagem
     */
    public function getReduceProgressPercentage(): float
    {
        if ($this->reduce_total_batches === 0) {
            return 0;
        }

        return round(($this->reduce_processed_batches / $this->reduce_total_batches) * 100, 2);
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

        return round(($this->processed_documents_count / $this->total_documents) * 100, 2);
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

    // =========================================
    // Métodos legados (mantidos para compatibilidade)
    // =========================================

    /**
     * @deprecated Use initializeMapReduce() instead
     */
    public function initializeEvolutionaryAnalysis(int $totalDocuments): void
    {
        $this->initializeMapReduce($totalDocuments);
    }

    /**
     * @deprecated Não usado no map-reduce
     */
    public function updateEvolutionaryState(int $documentIndex, string $summary): void
    {
        $this->update([
            'current_document_index' => $documentIndex,
            'processed_documents_count' => $documentIndex + 1,
            'evolutionary_summary' => $summary,
            'last_processed_at' => now(),
        ]);
    }

    /**
     * @deprecated Use markAsCompleted() instead
     */
    public function finalizeEvolutionaryAnalysis(string $finalAnalysis, int $processingTime): void
    {
        $this->update([
            'status' => 'completed',
            'ai_analysis' => $finalAnalysis,
            'processing_time_ms' => $processingTime,
            'is_resumable' => false,
            'last_processed_at' => now(),
        ]);
    }

    /**
     * @deprecated Não usado no map-reduce
     */
    public function getNextDocumentIndex(): int
    {
        return $this->current_document_index + 1;
    }

    /**
     * @deprecated Não usado no map-reduce
     */
    public function getEvolutionarySummary(): string
    {
        return $this->evolutionary_summary ?? '';
    }

    /**
     * @deprecated Use getMicroAnalysisStats() instead
     */
    public function hasMoreDocuments(): bool
    {
        return $this->processed_documents_count < $this->total_documents;
    }
}
