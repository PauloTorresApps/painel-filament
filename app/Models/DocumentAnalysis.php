<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAnalysis extends Model
{
    protected $fillable = [
        'user_id',
        'numero_processo',
        'id_documento',
        'descricao_documento',
        'extracted_text',
        'ai_analysis',
        'status',
        'error_message',
        'total_characters',
        'processing_time_ms',
    ];

    protected $casts = [
        'total_characters' => 'integer',
        'processing_time_ms' => 'integer',
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
}
