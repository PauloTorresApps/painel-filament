<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AiModel extends Model
{
    public const PURPOSE_PDF_TEXT = 'pdf_text';
    public const PURPOSE_PDF_OCR = 'pdf_ocr';
    public const PURPOSE_VISION = 'vision';
    public const PURPOSE_LARGE_CONTEXT = 'large_context';

    protected $fillable = [
        'name',
        'provider',
        'model_id',
        'description',
        'is_active',
        'supports_reasoning',
        'supports_vision',
        'purpose',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'supports_reasoning' => 'boolean',
        'supports_vision' => 'boolean',
        'purpose' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (AiModel $model) {
            $newPurposes = $model->purpose ?? [];

            if (empty($newPurposes)) {
                return;
            }

            // Para cada propósito sendo atribuído, remove de outros modelos ativos
            $modelId = $model->exists ? $model->id : 0;

            foreach ($newPurposes as $purpose) {
                $others = self::where('id', '!=', $modelId)
                    ->where('is_active', true)
                    ->whereJsonContains('purpose', $purpose)
                    ->get();

                foreach ($others as $other) {
                    $otherPurposes = $other->purpose ?? [];
                    $otherPurposes = array_values(array_diff($otherPurposes, [$purpose]));
                    $other->updateQuietly(['purpose' => $otherPurposes ?: null]);

                    Log::info('AiModel: Removido propósito de outro modelo', [
                        'purpose' => $purpose,
                        'removed_from' => $other->id,
                        'assigned_to' => $modelId,
                    ]);
                }
            }
        });
    }

    /**
     * Retorna os prompts que utilizam este modelo
     */
    public function prompts(): HasMany
    {
        return $this->hasMany(AiPrompt::class);
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
     * Retorna os propósitos disponíveis para roteamento de modelos
     */
    public static function getAvailablePurposes(): array
    {
        return [
            self::PURPOSE_PDF_TEXT => 'PDF Textual',
            self::PURPOSE_PDF_OCR => 'PDF OCR (Escaneado)',
            self::PURPOSE_VISION => 'Visão (Imagens)',
            self::PURPOSE_LARGE_CONTEXT => 'Contexto Grande',
        ];
    }

    /**
     * Busca o model_id do modelo ativo atribuído a um propósito.
     * Retorna null se nenhum modelo estiver cadastrado para o propósito.
     */
    public static function getModelIdForPurpose(string $purpose): ?string
    {
        return Cache::remember("ai_model_purpose:{$purpose}", now()->addMinutes(10), function () use ($purpose) {
            return self::where('is_active', true)
                ->whereJsonContains('purpose', $purpose)
                ->value('model_id');
        });
    }

    /**
     * Retorna as atribuições atuais de propósitos (excluindo o modelo informado).
     * Formato: ['pdf_text' => 'Nome do Modelo', ...]
     */
    public static function getCurrentPurposeAssignments(?int $excludeModelId = null): array
    {
        $query = self::where('is_active', true)
            ->whereNotNull('purpose');

        if ($excludeModelId) {
            $query->where('id', '!=', $excludeModelId);
        }

        $assignments = [];
        foreach ($query->get() as $model) {
            foreach ($model->purpose ?? [] as $purpose) {
                $assignments[$purpose] = $model->name;
            }
        }

        return $assignments;
    }
}
