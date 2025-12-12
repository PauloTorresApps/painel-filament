<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class System extends Model
{
    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function judicialUsers(): HasMany
    {
        return $this->hasMany(JudicialUser::class);
    }

    public function aiPrompts(): HasMany
    {
        return $this->hasMany(AiPrompt::class);
    }
}
