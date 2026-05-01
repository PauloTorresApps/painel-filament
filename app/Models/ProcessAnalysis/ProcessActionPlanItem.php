<?php

namespace App\Models\ProcessAnalysis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessActionPlanItem extends Model
{
    protected $fillable = [
        'process_structured_opinion_id',
        'prioridade',
        'ato_recomendado',
        'objetivo',
        'fundamento',
        'prazo',
        'urgencia',
        'risco_de_nao_agir',
        'documentos_necessarios',
        'grau_confianca',
        'ordem',
    ];

    protected $casts = [
        'urgencia' => 'integer',
        'risco_de_nao_agir' => 'integer',
        'documentos_necessarios' => 'array',
        'grau_confianca' => 'integer',
        'ordem' => 'integer',
    ];

    public function processStructuredOpinion(): BelongsTo
    {
        return $this->belongsTo(ProcessStructuredOpinion::class);
    }
}
