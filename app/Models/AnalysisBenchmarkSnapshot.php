<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalysisBenchmarkSnapshot extends Model
{
    protected $fillable = [
        'snapshot_date',
        'window_days',
        'metrics',
        'slo_breaches',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'window_days' => 'integer',
        'metrics' => 'array',
        'slo_breaches' => 'array',
    ];
}
