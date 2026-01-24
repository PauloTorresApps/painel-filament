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
    ];

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
            'total_documents' => $totalDocuments,
            'current_document_index' => 0,
            'processed_documents_count' => 0,
            'is_resumable' => true,
            'last_processed_at' => now(),
        ]);
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
