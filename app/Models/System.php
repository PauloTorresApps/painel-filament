<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class System extends Model
{
    public const NAME_CONTRATOS = 'Contratos';

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

    public static function contratos(): ?self
    {
        return Cache::remember('system.contratos', now()->addHour(), fn () =>
            self::where('name', self::NAME_CONTRATOS)->first()
        );
    }
}
