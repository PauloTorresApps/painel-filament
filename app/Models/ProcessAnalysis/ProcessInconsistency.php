<?php

namespace App\Models\ProcessAnalysis;

use App\Models\DocumentAnalysis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessInconsistency extends Model
{
    protected $fillable = [
        'document_analysis_id',
        'tipo',
        'descricao',
        'eventos_relacionados',
        'possivel_consequencia',
        'score',
    ];

    protected $casts = [
        'eventos_relacionados' => 'array',
        'score' => 'integer',
    ];

    public function documentAnalysis(): BelongsTo
    {
        return $this->belongsTo(DocumentAnalysis::class);
    }
}
