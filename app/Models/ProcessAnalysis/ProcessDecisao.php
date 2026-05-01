<?php

namespace App\Models\ProcessAnalysis;

use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessDecisao extends Model
{
    protected $table = 'process_decisoes';

    protected $fillable = [
        'document_analysis_id',
        'document_micro_analysis_id',
        'evento_ou_id',
        'data',
        'autoridade',
        'conteudo',
        'efeito_juridico',
        'resultado',
    ];

    protected $casts = [
        'data' => 'date',
    ];

    public function documentAnalysis(): BelongsTo
    {
        return $this->belongsTo(DocumentAnalysis::class);
    }

    public function documentMicroAnalysis(): BelongsTo
    {
        return $this->belongsTo(DocumentMicroAnalysis::class);
    }
}
