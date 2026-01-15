<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractAnalysis extends Model
{
    protected $fillable = [
        'user_id',
        'prompt_id',
        'file_name',
        'file_path',
        'file_size',
        'status',
        'ai_provider',
        'analysis_result',
        'error_message',
        'processing_time_ms',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'processing_time_ms' => 'integer',
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
     * Relação com o prompt de IA
     */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(AiPrompt::class, 'prompt_id');
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
}
