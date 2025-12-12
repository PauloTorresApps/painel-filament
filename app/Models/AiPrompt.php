<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPrompt extends Model
{
    protected $fillable = [
        'user_id',
        'system_id',
        'title',
        'content',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class);
    }
}
