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
        'infographic_storyboard_prompt_id',
        'infographic_html_prompt_id',
        'file_name',
        'file_path',
        'file_size',
        'interested_party_name',
        'status',
        'legal_opinion_status',
        'infographic_status',
        'ai_provider',
        'legal_opinion_ai_provider',
        'analysis_result',
        'analysis_ai_metadata',
        'legal_opinion_result',
        'legal_opinion_ai_metadata',
        'infographic_storyboard_json',
        'infographic_html_result',
        'infographic_ai_metadata',
        'error_message',
        'legal_opinion_error',
        'infographic_error',
        'processing_time_ms',
        'legal_opinion_processing_time_ms',
        'infographic_processing_time_ms',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'processing_time_ms' => 'integer',
        'legal_opinion_processing_time_ms' => 'integer',
        'infographic_processing_time_ms' => 'integer',
        'analysis_ai_metadata' => 'array',
        'legal_opinion_ai_metadata' => 'array',
        'infographic_ai_metadata' => 'array',
    ];

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

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
            self::STATUS_CANCELLED => 'gray',
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
            self::STATUS_CANCELLED => 'Cancelada',
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
    public function markAsCompleted(string $result, int $processingTimeMs, ?array $aiMetadata = null): void
    {
        $data = [
            'status' => self::STATUS_COMPLETED,
            'analysis_result' => $result,
            'processing_time_ms' => $processingTimeMs,
        ];

        if ($aiMetadata !== null) {
            $data['analysis_ai_metadata'] = $aiMetadata;
        }

        $this->update($data);
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

    /**
     * Verifica se a análise foi cancelada
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Verifica se a análise pode ser cancelada
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }

    /**
     * Marca a análise como cancelada
     */
    public function markAsCancelled(): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'error_message' => 'Análise cancelada pelo usuário.',
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
            self::STATUS_CANCELLED => 'gray',
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
            self::STATUS_CANCELLED => 'Cancelado',
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
    public function markLegalOpinionAsCompleted(string $result, int $processingTimeMs, ?array $aiMetadata = null): void
    {
        $data = [
            'legal_opinion_status' => self::STATUS_COMPLETED,
            'legal_opinion_result' => $result,
            'legal_opinion_processing_time_ms' => $processingTimeMs,
        ];

        if ($aiMetadata !== null) {
            $data['legal_opinion_ai_metadata'] = $aiMetadata;
        }

        $this->update($data);
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

    /**
     * Verifica se o parecer jurídico foi cancelado
     */
    public function isLegalOpinionCancelled(): bool
    {
        return $this->legal_opinion_status === self::STATUS_CANCELLED;
    }

    /**
     * Verifica se o parecer jurídico pode ser cancelado
     */
    public function canLegalOpinionBeCancelled(): bool
    {
        return $this->legal_opinion_status === self::STATUS_PROCESSING;
    }

    /**
     * Marca o parecer jurídico como cancelado
     */
    public function markLegalOpinionAsCancelled(): void
    {
        $this->update([
            'legal_opinion_status' => self::STATUS_CANCELLED,
            'legal_opinion_error' => 'Geração de parecer cancelada pelo usuário.',
        ]);
    }

    // ========== Métodos para Infográfico ==========

    /**
     * Relação com o prompt de IA (storyboard do infográfico)
     */
    public function infographicStoryboardPrompt(): BelongsTo
    {
        return $this->belongsTo(AiPrompt::class, 'infographic_storyboard_prompt_id');
    }

    /**
     * Relação com o prompt de IA (HTML do infográfico)
     */
    public function infographicHtmlPrompt(): BelongsTo
    {
        return $this->belongsTo(AiPrompt::class, 'infographic_html_prompt_id');
    }

    /**
     * Retorna a cor do badge de status do infográfico
     */
    public function getInfographicStatusBadgeColorAttribute(): string
    {
        return match ($this->infographic_status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_PROCESSING => 'info',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_FAILED => 'danger',
            self::STATUS_CANCELLED => 'gray',
            default => 'gray',
        };
    }

    /**
     * Retorna o label do status do infográfico em português
     */
    public function getInfographicStatusLabelAttribute(): string
    {
        return match ($this->infographic_status) {
            self::STATUS_PENDING => 'Pendente',
            self::STATUS_PROCESSING => 'Gerando...',
            self::STATUS_COMPLETED => 'Concluído',
            self::STATUS_FAILED => 'Falhou',
            self::STATUS_CANCELLED => 'Cancelado',
            default => 'Não iniciado',
        };
    }

    /**
     * Verifica se o infográfico está em andamento
     */
    public function isInfographicProcessing(): bool
    {
        return $this->infographic_status === self::STATUS_PROCESSING;
    }

    /**
     * Verifica se o infográfico foi concluído
     */
    public function isInfographicCompleted(): bool
    {
        return $this->infographic_status === self::STATUS_COMPLETED;
    }

    /**
     * Verifica se o infográfico falhou
     */
    public function isInfographicFailed(): bool
    {
        return $this->infographic_status === self::STATUS_FAILED;
    }

    /**
     * Verifica se o infográfico foi cancelado
     */
    public function isInfographicCancelled(): bool
    {
        return $this->infographic_status === self::STATUS_CANCELLED;
    }

    /**
     * Verifica se pode gerar infográfico
     * (parecer jurídico deve estar concluído e infográfico não pode estar em processamento)
     */
    public function canGenerateInfographic(): bool
    {
        return $this->isLegalOpinionCompleted()
            && !$this->isInfographicProcessing()
            && !empty($this->legal_opinion_result);
    }

    /**
     * Verifica se o infográfico pode ser cancelado
     */
    public function canInfographicBeCancelled(): bool
    {
        return $this->infographic_status === self::STATUS_PROCESSING;
    }

    /**
     * Marca o infográfico como processando
     */
    public function markInfographicAsProcessing(): void
    {
        $this->update(['infographic_status' => self::STATUS_PROCESSING]);
    }

    /**
     * Marca o infográfico como concluído
     */
    public function markInfographicAsCompleted(
        string $storyboardJson,
        string $htmlResult,
        int $processingTimeMs,
        ?array $aiMetadata = null
    ): void {
        $data = [
            'infographic_status' => self::STATUS_COMPLETED,
            'infographic_storyboard_json' => $storyboardJson,
            'infographic_html_result' => $htmlResult,
            'infographic_processing_time_ms' => $processingTimeMs,
        ];

        if ($aiMetadata !== null) {
            $data['infographic_ai_metadata'] = $aiMetadata;
        }

        $this->update($data);
    }

    /**
     * Marca o infográfico como falha
     */
    public function markInfographicAsFailed(string $errorMessage): void
    {
        $this->update([
            'infographic_status' => self::STATUS_FAILED,
            'infographic_error' => $errorMessage,
        ]);
    }

    /**
     * Marca o infográfico como cancelado
     */
    public function markInfographicAsCancelled(): void
    {
        $this->update([
            'infographic_status' => self::STATUS_CANCELLED,
            'infographic_error' => 'Geração de infográfico cancelada pelo usuário.',
        ]);
    }
}
