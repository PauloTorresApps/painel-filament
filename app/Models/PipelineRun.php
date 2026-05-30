<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PipelineRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'graph_name',
        'entity_type',
        'entity_id',
        'status',
        'current_node',
        'state',
    ];

    protected $casts = [
        'state' => 'array',
        'entity_id' => 'integer',
    ];
}
