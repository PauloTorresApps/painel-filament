<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class AiPrompt extends Model
{
    // Tipos de prompt para sistema de Contratos
    public const TYPE_ANALYSIS = 'analysis';
    public const TYPE_LEGAL_OPINION = 'legal_opinion';
    public const TYPE_STORYBOARD = 'storyboard';
    public const TYPE_INFOGRAPHIC = 'infographic';

    // Tipos de prompt para Processos Judiciais (Map-Reduce)
    public const TYPE_DOCUMENT_ANALYSIS = 'document_analysis';  // Análise individual de documentos (fase MAP)
    public const TYPE_FINAL_OPINION = 'final_opinion';          // Parecer final consolidado (fase REDUCE)
    public const TYPE_INVENTORY_CONSOLIDATION = 'inventory_consolidation';
    public const TYPE_CHRONOLOGY_BUILDER = 'chronology_builder';
    public const TYPE_ENGINE_INTELLIGENCE = 'engine_intelligence';
    public const TYPE_PARECER_STRUCTURED = 'parecer_structured';
    public const TYPE_DESIGNER_BRIEF = 'designer_brief';

    protected $fillable = [
        'system_id',
        'prompt_type',
        'title',
        'content',
        'ai_provider',
        'ai_model_id',
        'deep_thinking_enabled',
        'analysis_strategy',
        'temperature',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'deep_thinking_enabled' => 'boolean',
        'temperature' => 'float',
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

            // Se este prompt está sendo definido como padrão,
            // remove o padrão APENAS dos outros prompts do mesmo sistema E MESMO tipo
            if ($prompt->is_default && $prompt->isDirty('is_default')) {
                $promptType = $prompt->prompt_type;
                $systemId = $prompt->system_id;
                $promptId = $prompt->exists ? $prompt->id : 0;

                Log::info('AiPrompt: Definindo prompt como padrão', [
                    'prompt_id' => $promptId,
                    'system_id' => $systemId,
                    'prompt_type' => $promptType,
                ]);

                // Constrói query base
                $query = self::where('system_id', $systemId)
                    ->where('is_default', true)
                    ->where('id', '!=', $promptId);

                // IMPORTANTE: Filtra SEMPRE por prompt_type para permitir
                // múltiplos defaults (um por tipo)
                if (!empty($promptType)) {
                    // Só remove default de prompts com o MESMO tipo
                    $query->where('prompt_type', $promptType);
                } else {
                    // Se não tem tipo, só afeta prompts sem tipo
                    $query->where(function ($q) {
                        $q->whereNull('prompt_type')
                          ->orWhere('prompt_type', '');
                    });
                }

                // Log da query para debug
                $affectedCount = $query->count();
                Log::info('AiPrompt: Removendo default de outros prompts', [
                    'affected_count' => $affectedCount,
                    'query_sql' => $query->toRawSql(),
                ]);

                $query->update(['is_default' => false]);
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
            'openrouter' => 'OpenRouter',
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
     * Retorna os tipos de prompt disponíveis para processos judiciais
     */
    public static function getJudicialPromptTypes(): array
    {
        return [
            self::TYPE_DOCUMENT_ANALYSIS => 'Análise de Documentos (MAP)',
            self::TYPE_FINAL_OPINION => 'Parecer Final (REDUCE)',
            self::TYPE_INVENTORY_CONSOLIDATION => 'Inventário Estruturado',
            self::TYPE_CHRONOLOGY_BUILDER => 'Cronologia Processual',
            self::TYPE_ENGINE_INTELLIGENCE => 'Engine Processual',
            self::TYPE_PARECER_STRUCTURED => 'Parecer Estruturado (OWLEX)',
            self::TYPE_DESIGNER_BRIEF => 'Designer Brief (Dashboard)',
        ];
    }

    /**
     * Retorna todos os tipos de prompt disponíveis
     */
    public static function getAllPromptTypes(): array
    {
        return array_merge(
            self::getContractPromptTypes(),
            self::getJudicialPromptTypes()
        );
    }

    /**
     * Retorna o label do tipo de prompt
     */
    public function getPromptTypeLabelAttribute(): ?string
    {
        if (!$this->prompt_type) {
            return null;
        }

        return self::getAllPromptTypes()[$this->prompt_type] ?? $this->prompt_type;
    }

    /**
     * Busca o prompt padrão para um sistema e tipo específico
     */
    public static function getDefaultForSystemAndType(int $systemId, string $promptType): ?self
    {
        return self::where('system_id', $systemId)
            ->where('prompt_type', $promptType)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
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
