<?php

namespace App\Models\ProcessAnalysis;

use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcessInventoryItem extends Model
{
    protected $fillable = [
        'document_analysis_id',
        'document_micro_analysis_id',
        'evento_ou_id',
        'data',
        'tipo',
        'classificacao',
        'resumo',
        'relevancia',
        'duplicado_de_item_id',
        'legivel',
        'completo',
        'observacoes',
        'content_hash',
    ];

    protected $casts = [
        'data' => 'date',
        'relevancia' => 'integer',
        'legivel' => 'boolean',
        'completo' => 'boolean',
    ];

    public function documentAnalysis(): BelongsTo
    {
        return $this->belongsTo(DocumentAnalysis::class);
    }

    public function documentMicroAnalysis(): BelongsTo
    {
        return $this->belongsTo(DocumentMicroAnalysis::class);
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicado_de_item_id');
    }

    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'duplicado_de_item_id');
    }
}
