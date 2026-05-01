<?php

namespace App\Models\ProcessAnalysis;

use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessPedido extends Model
{
    protected $fillable = [
        'document_analysis_id',
        'document_micro_analysis_id',
        'parte',
        'pedido',
        'fundamento',
        'status',
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
