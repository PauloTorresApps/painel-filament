<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPrompt extends Model
{
    // Tipos de prompt para sistema de Contratos
    public const TYPE_ANALYSIS = 'analysis';
    public const TYPE_LEGAL_OPINION = 'legal_opinion';

    protected $fillable = [
        'system_id',
        'prompt_type',
        'title',
        'content',
        'ai_provider',
        'deep_thinking_enabled',
        'analysis_strategy',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'deep_thinking_enabled' => 'boolean',
    ];

    protected $attributes = [
        'analysis_strategy' => 'evolutionary',
    ];

    protected $appends = [
        'provider_badge_color',
        'prompt_type_label',
    ];

    /**
     * Get the badge color for the AI provider
     */
    public function getProviderBadgeColorAttribute(): string
    {
        return $this->is_default ? 'success' : 'gray';
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class);
    }

    /**
     * Retorna os providers de IA disponíveis
     */
    public static function getAvailableProviders(): array
    {
        return [
            'gemini' => 'Google Gemini',
            'openai' => 'OpenAI (ChatGPT)',
            'deepseek' => 'DeepSeek',
        ];
    }

    /**
     * Retorna as estratégias de análise disponíveis
     */
    public static function getAvailableStrategies(): array
    {
        return [
            'hierarchical' => 'Pipeline Hierárquico (padrão)',
            'evolutionary' => 'Resumo Evolutivo (recomendado para muitos documentos)',
        ];
    }

    /**
     * Retorna os tipos de prompt disponíveis para contratos
     */
    public static function getContractPromptTypes(): array
    {
        return [
            self::TYPE_ANALYSIS => 'Análise de Contrato',
            self::TYPE_LEGAL_OPINION => 'Parecer Jurídico',
        ];
    }

    /**
     * Retorna o label do tipo de prompt
     */
    public function getPromptTypeLabelAttribute(): ?string
    {
        if (!$this->prompt_type) {
            return null;
        }

        return self::getContractPromptTypes()[$this->prompt_type] ?? $this->prompt_type;
    }
}
