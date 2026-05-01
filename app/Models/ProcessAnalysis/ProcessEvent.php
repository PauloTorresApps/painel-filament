<?php

namespace App\Models\ProcessAnalysis;

use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcessEvent extends Model
{
    protected $fillable = [
        'document_analysis_id',
        'source_micro_analysis_id',
        'data',
        'evento_ou_id',
        'tipo_de_ato',
        'autor_do_ato',
        'resumo_objetivo',
        'efeito_juridico',
        'abriu_prazo',
        'prazo_identificado',
        'marco_relevante',
        'ordem',
    ];

    protected $casts = [
        'data' => 'date',
        'abriu_prazo' => 'boolean',
        'marco_relevante' => 'boolean',
        'ordem' => 'integer',
    ];

    public function documentAnalysis(): BelongsTo
    {
        return $this->belongsTo(DocumentAnalysis::class);
    }

    public function sourceMicroAnalysis(): BelongsTo
    {
        return $this->belongsTo(DocumentMicroAnalysis::class, 'source_micro_analysis_id');
    }

    public function deadlines(): HasMany
    {
        return $this->hasMany(ProcessDeadline::class);
    }

    public function risks(): HasMany
    {
        return $this->hasMany(ProcessRisk::class);
    }
}
