<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JudicialUser extends Model
{
    protected $fillable = [
        'user_id',
        'system_id',
        'user_login',
        'is_default',
    ];

    protected $casts = [
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

    /**
     * Método chamado antes de salvar para garantir apenas um default por usuário
     */
    protected static function booted()
    {
        static::saving(function ($judicialUser) {
            if ($judicialUser->is_default) {
                // Remove o flag is_default de outros usuários judiciais do mesmo usuário
                static::where('user_id', $judicialUser->user_id)
                    ->where('id', '!=', $judicialUser->id)
                    ->update(['is_default' => false]);
            }
        });
    }

    /**
     * Scope para buscar o usuário judicial padrão de um usuário
     */
    public function scopeDefault($query, $userId)
    {
        return $query->where('user_id', $userId)->where('is_default', true);
    }
}
