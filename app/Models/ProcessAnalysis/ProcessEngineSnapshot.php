<?php

namespace App\Models\ProcessAnalysis;

use App\Models\DocumentAnalysis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessEngineSnapshot extends Model
{
    protected $fillable = [
        'document_analysis_id',
        'risco_processual_score',
        'urgencia_score',
        'oportunidade_score',
        'confiabilidade_score',
        'situacao_atual',
        'pendencias',
        'generated_at',
    ];

    protected $casts = [
        'situacao_atual' => 'array',
        'pendencias' => 'array',
        'generated_at' => 'datetime',
        'risco_processual_score' => 'integer',
        'urgencia_score' => 'integer',
        'oportunidade_score' => 'integer',
        'confiabilidade_score' => 'integer',
    ];

    public function documentAnalysis(): BelongsTo
    {
        return $this->belongsTo(DocumentAnalysis::class);
    }
}
