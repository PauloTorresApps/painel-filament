<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPrompt extends Model
{
    // Tipos de prompt para sistema de Contratos
    public const TYPE_ANALYSIS = 'analysis';
    public const TYPE_LEGAL_OPINION = 'legal_opinion';
    public const TYPE_STORYBOARD = 'storyboard';
    public const TYPE_INFOGRAPHIC = 'infographic';

    protected $fillable = [
        'system_id',
        'prompt_type',
        'title',
        'content',
        'ai_provider',
        'ai_model_id',
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

    protected static function booted(): void
    {
        static::saving(function (AiPrompt $prompt) {
            // Atualiza o ai_provider automaticamente baseado no modelo selecionado
            if ($prompt->ai_model_id) {
                $model = AiModel::find($prompt->ai_model_id);
                if ($model) {
                    $prompt->ai_provider = $model->provider;
                }
            }
        });
    }

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

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
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
            self::TYPE_STORYBOARD => 'Storyboard (JSON)',
            self::TYPE_INFOGRAPHIC => 'Infográfico (HTML)',
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

    /**
     * Verifica se os prompts necessários para geração de infográfico existem
     *
     * @return array{exists: bool, missing: array<string>}
     */
    public static function checkInfographicPromptsExist(): array
    {
        $system = \App\Models\System::where('name', 'Contratos')->first();

        if (!$system) {
            return [
                'exists' => false,
                'missing' => ['Sistema "Contratos" não encontrado'],
            ];
        }

        $missing = [];

        // Verifica prompt de storyboard
        $storyboardExists = self::where('system_id', $system->id)
            ->where('prompt_type', self::TYPE_STORYBOARD)
            ->where('is_default', true)
            ->where('is_active', true)
            ->exists();

        if (!$storyboardExists) {
            $missing[] = 'Storyboard (JSON)';
        }

        // Verifica prompt de infográfico HTML
        $infographicExists = self::where('system_id', $system->id)
            ->where('prompt_type', self::TYPE_INFOGRAPHIC)
            ->where('is_default', true)
            ->where('is_active', true)
            ->exists();

        if (!$infographicExists) {
            $missing[] = 'Infográfico (HTML)';
        }

        return [
            'exists' => empty($missing),
            'missing' => $missing,
        ];
    }
}
