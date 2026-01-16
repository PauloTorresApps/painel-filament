<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractAnalysis extends Model
{
    protected $fillable = [
        'user_id',
        'prompt_id',
        'legal_opinion_prompt_id',
        'file_name',
        'file_path',
        'file_size',
        'interested_party_name',
        'status',
        'legal_opinion_status',
        'ai_provider',
        'legal_opinion_ai_provider',
        'analysis_result',
        'legal_opinion_result',
        'error_message',
        'legal_opinion_error',
        'processing_time_ms',
        'legal_opinion_processing_time_ms',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'processing_time_ms' => 'integer',
        'legal_opinion_processing_time_ms' => 'integer',
    ];

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Relação com o usuário
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relação com o prompt de IA (análise)
     */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(AiPrompt::class, 'prompt_id');
    }

    /**
     * Relação com o prompt de IA (parecer jurídico)
     */
    public function legalOpinionPrompt(): BelongsTo
    {
        return $this->belongsTo(AiPrompt::class, 'legal_opinion_prompt_id');
    }

    /**
     * Retorna o tamanho do arquivo formatado
     */
    public function getFormattedFileSizeAttribute(): string
    {
        $bytes = $this->file_size;

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' bytes';
    }

    /**
     * Retorna a cor do badge de status
     */
    public function getStatusBadgeColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_PROCESSING => 'info',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }

    /**
     * Retorna o label do status em português
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pendente',
            self::STATUS_PROCESSING => 'Processando',
            self::STATUS_COMPLETED => 'Concluída',
            self::STATUS_FAILED => 'Falhou',
            default => 'Desconhecido',
        };
    }

    /**
     * Verifica se a análise está em andamento
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Verifica se a análise foi concluída
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Verifica se a análise falhou
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Marca a análise como processando
     */
    public function markAsProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    /**
     * Marca a análise como concluída
     */
    public function markAsCompleted(string $result, int $processingTimeMs): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'analysis_result' => $result,
            'processing_time_ms' => $processingTimeMs,
        ]);
    }

    /**
     * Marca a análise como falha
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);
    }

    // ========== Métodos para Parecer Jurídico ==========

    /**
     * Retorna a cor do badge de status do parecer jurídico
     */
    public function getLegalOpinionStatusBadgeColorAttribute(): string
    {
        return match ($this->legal_opinion_status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_PROCESSING => 'info',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }

    /**
     * Retorna o label do status do parecer jurídico em português
     */
    public function getLegalOpinionStatusLabelAttribute(): string
    {
        return match ($this->legal_opinion_status) {
            self::STATUS_PENDING => 'Pendente',
            self::STATUS_PROCESSING => 'Gerando...',
            self::STATUS_COMPLETED => 'Concluído',
            self::STATUS_FAILED => 'Falhou',
            default => 'Não iniciado',
        };
    }

    /**
     * Verifica se o parecer jurídico está em andamento
     */
    public function isLegalOpinionProcessing(): bool
    {
        return $this->legal_opinion_status === self::STATUS_PROCESSING;
    }

    /**
     * Verifica se o parecer jurídico foi concluído
     */
    public function isLegalOpinionCompleted(): bool
    {
        return $this->legal_opinion_status === self::STATUS_COMPLETED;
    }

    /**
     * Verifica se o parecer jurídico falhou
     */
    public function isLegalOpinionFailed(): bool
    {
        return $this->legal_opinion_status === self::STATUS_FAILED;
    }

    /**
     * Verifica se pode gerar parecer jurídico
     * (análise deve estar concluída e parecer não pode estar em processamento)
     */
    public function canGenerateLegalOpinion(): bool
    {
        return $this->isCompleted()
            && !$this->isLegalOpinionProcessing()
            && !empty($this->analysis_result);
    }

    /**
     * Marca o parecer jurídico como processando
     */
    public function markLegalOpinionAsProcessing(): void
    {
        $this->update(['legal_opinion_status' => self::STATUS_PROCESSING]);
    }

    /**
     * Marca o parecer jurídico como concluído
     */
    public function markLegalOpinionAsCompleted(string $result, int $processingTimeMs): void
    {
        $this->update([
            'legal_opinion_status' => self::STATUS_COMPLETED,
            'legal_opinion_result' => $result,
            'legal_opinion_processing_time_ms' => $processingTimeMs,
        ]);
    }

    /**
     * Marca o parecer jurídico como falha
     */
    public function markLegalOpinionAsFailed(string $errorMessage): void
    {
        $this->update([
            'legal_opinion_status' => self::STATUS_FAILED,
            'legal_opinion_error' => $errorMessage,
        ]);
    }
}
