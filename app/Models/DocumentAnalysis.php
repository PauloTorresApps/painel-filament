<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'evolutionary_summary',
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
     * Inicializa análise com Resumo Evolutivo
     */
    public function initializeEvolutionaryAnalysis(int $totalDocuments): void
    {
        $this->update([
            'status' => 'processing',
            'total_documents' => $totalDocuments,
            'current_document_index' => 0,
            'processed_documents_count' => 0,
            'evolutionary_summary' => '',
            'is_resumable' => true,
            'last_processed_at' => now(),
        ]);
    }

    /**
     * Atualiza o estado evolutivo após processar um documento
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
     * Finaliza análise evolutiva com sucesso
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
     * Verifica se a análise pode ser retomada
     */
    public function canBeResumed(): bool
    {
        return $this->is_resumable
            && $this->status === 'processing'
            && $this->processed_documents_count < $this->total_documents;
    }

    /**
     * Retorna o índice do próximo documento a processar
     */
    public function getNextDocumentIndex(): int
    {
        return $this->current_document_index + 1;
    }

    /**
     * Retorna o resumo evolutivo atual
     */
    public function getEvolutionarySummary(): string
    {
        return $this->evolutionary_summary ?? '';
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
     * Verifica se ainda há documentos para processar
     */
    public function hasMoreDocuments(): bool
    {
        return $this->processed_documents_count < $this->total_documents;
    }
}
