<?php

namespace App\Models\ProcessAnalysis;

use App\Models\DocumentAnalysis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcessStructuredOpinion extends Model
{
    protected $fillable = [
        'document_analysis_id',
        'sumario_executivo',
        'diagnostico',
        'prazos_preclusoes',
        'prescricao_decadencia',
        'inconsistencias_atencao',
        'riscos_priorizados',
        'oportunidades',
        'conclusao_estrategica',
    ];

    protected $casts = [
        'riscos_priorizados' => 'array',
        'oportunidades' => 'array',
    ];

    public function documentAnalysis(): BelongsTo
    {
        return $this->belongsTo(DocumentAnalysis::class);
    }

    public function actionPlanItems(): HasMany
    {
        return $this->hasMany(ProcessActionPlanItem::class);
    }
}
