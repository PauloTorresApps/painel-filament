<?php

namespace App\Models\ProcessAnalysis;

use App\Models\DocumentAnalysis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessOpportunity extends Model
{
    protected $fillable = [
        'document_analysis_id',
        'descricao',
        'prioridade',
        'objetivo',
        'fundamento',
        'ato_recomendado',
        'score',
    ];

    protected $casts = [
        'score' => 'integer',
    ];

    public function documentAnalysis(): BelongsTo
    {
        return $this->belongsTo(DocumentAnalysis::class);
    }
}
