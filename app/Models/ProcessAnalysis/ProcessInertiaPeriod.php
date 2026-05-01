<?php

namespace App\Models\ProcessAnalysis;

use App\Models\DocumentAnalysis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessInertiaPeriod extends Model
{
    protected $fillable = [
        'document_analysis_id',
        'data_inicio',
        'data_fim',
        'responsavel_aparente',
        'possivel_consequencia',
        'duracao_dias',
    ];

    protected $casts = [
        'data_inicio' => 'date',
        'data_fim' => 'date',
        'duracao_dias' => 'integer',
    ];

    public function documentAnalysis(): BelongsTo
    {
        return $this->belongsTo(DocumentAnalysis::class);
    }
}
