<?php

namespace App\Models\ProcessAnalysis;

use App\Models\DocumentAnalysis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessRisk extends Model
{
    protected $fillable = [
        'document_analysis_id',
        'process_event_id',
        'descricao',
        'nivel',
        'categoria',
        'impacto',
        'urgencia',
        'fundamento',
        'score',
    ];

    protected $casts = [
        'impacto' => 'integer',
        'urgencia' => 'integer',
        'score' => 'integer',
    ];

    public function documentAnalysis(): BelongsTo
    {
        return $this->belongsTo(DocumentAnalysis::class);
    }

    public function processEvent(): BelongsTo
    {
        return $this->belongsTo(ProcessEvent::class);
    }
}
