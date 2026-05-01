<?php

namespace App\Models\ProcessAnalysis;

use App\Models\DocumentAnalysis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessDeadline extends Model
{
    protected $fillable = [
        'document_analysis_id',
        'process_event_id',
        'tipo',
        'ato',
        'marco_inicial',
        'regra_de_contagem',
        'prazo',
        'data_final_estimada',
        'foi_cumprido',
        'regime_juridico',
        'atos_interruptivos',
        'conclusao',
        'status',
    ];

    protected $casts = [
        'marco_inicial' => 'date',
        'data_final_estimada' => 'date',
        'foi_cumprido' => 'boolean',
        'atos_interruptivos' => 'array',
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
