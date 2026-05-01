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
    public const TYPE_TIMELINE_INSTRUCTIONS = 'timeline_instructions';
    public const TYPE_ANALYST_JSON_INSTRUCTIONS = 'analyst_json_instructions';
    public const TYPE_MAP_STRUCTURED_FORMAT = 'map_structured_format';
    public const TYPE_MAP_FREETEXT_FORMAT = 'map_freetext_format';
    public const TYPE_CHUNK_ANALYSIS = 'chunk_analysis';
    public const TYPE_CHUNK_CONSOLIDATION = 'chunk_consolidation';
    public const TYPE_SYSTEM_ROLE = 'system_role';
    public const TYPE_REDUCE_CONSOLIDATION = 'reduce_consolidation';
    public const TYPE_FINAL_OPINION_WRAPPER = 'final_opinion_wrapper';

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
            self::TYPE_SYSTEM_ROLE => 'Papel do Assistente (System Role)',
            self::TYPE_MAP_STRUCTURED_FORMAT => 'Formato MAP Estruturado',
            self::TYPE_MAP_FREETEXT_FORMAT => 'Formato MAP Texto Livre',
            self::TYPE_TIMELINE_INSTRUCTIONS => 'Instruções de Timeline (MAP/Chunk)',
            self::TYPE_ANALYST_JSON_INSTRUCTIONS => 'Instruções de analista_json (MAP)',
            self::TYPE_CHUNK_ANALYSIS => 'Análise de Chunks (Chunk)',
            self::TYPE_CHUNK_CONSOLIDATION => 'Consolidação de Chunks (Chunk)',
            self::TYPE_REDUCE_CONSOLIDATION => 'Consolidação de Batch (REDUCE)',
            self::TYPE_FINAL_OPINION_WRAPPER => 'Template de Parecer Final (REDUCE)',
            self::TYPE_INVENTORY_CONSOLIDATION => 'Inventário Estruturado',
            self::TYPE_CHRONOLOGY_BUILDER => 'Cronologia Processual',
            self::TYPE_ENGINE_INTELLIGENCE => 'Engine Processual',
            self::TYPE_PARECER_STRUCTURED => 'Parecer Estruturado (OWLEX)',
            self::TYPE_DESIGNER_BRIEF => 'Designer Brief (Dashboard)',
        ];
    }

    /**
     * Resolve o conteúdo do prompt exclusivamente pelo banco.
     *
     * Requer um prompt padrão ativo para o tipo informado.
     */
    public static function resolvePromptContent(int $systemId, string $promptType): string
    {
        $prompt = self::getDefaultForSystemAndType($systemId, $promptType);

        if (!empty($prompt?->content)) {
            return $prompt->content;
        }

        $activeCount = self::where('system_id', $systemId)
            ->where('prompt_type', $promptType)
            ->where('is_active', true)
            ->count();

        $defaultCount = self::where('system_id', $systemId)
            ->where('prompt_type', $promptType)
            ->where('is_default', true)
            ->count();

        throw new \RuntimeException(sprintf(
            'Prompt obrigatório não configurado para system_id=%d e prompt_type=%s. Configure um prompt ativo e padrão para esta funcionalidade (ativos=%d, padrão=%d).',
            $systemId,
            $promptType,
            $activeCount,
            $defaultCount
        ));
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
