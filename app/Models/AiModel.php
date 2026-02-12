<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiModel extends Model
{
    protected $fillable = [
        'name',
        'provider',
        'model_id',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

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

}
