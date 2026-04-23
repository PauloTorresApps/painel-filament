<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisEvaluation extends Model
{
    protected $fillable = [
        'document_analysis_id',
        'run_id',
        'layer',
        'metric_name',
        'score',
        'threshold',
        'passed',
        'evidence',
    ];

    protected $casts = [
        'score' => 'float',
        'threshold' => 'float',
        'passed' => 'boolean',
        'evidence' => 'array',
    ];

    public function documentAnalysis(): BelongsTo
    {
        return $this->belongsTo(DocumentAnalysis::class);
    }
}
