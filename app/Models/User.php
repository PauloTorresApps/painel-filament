<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Filament\Panel;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\FilamentUser;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'default_dashboard_tab',
        'email_notifications_enabled',
        'email_notify_process_analysis',
        'email_notify_contract_analysis',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'email_notifications_enabled' => 'boolean',
            'email_notify_process_analysis' => 'boolean',
            'email_notify_contract_analysis' => 'boolean',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Painel admin: apenas Admin e Manager
        if ($panel->getId() === 'admin') {
            return $this->hasRole(['Admin', 'Manager']);
        }

        // Painel analises: todos os usuários autenticados com roles de análise
        if ($panel->getId() === 'analises') {
            return $this->hasRole(['Admin', 'Manager', 'Default', 'Analista de Contrato', 'Analista de Processo']);
        }

        return false;
    }

    public function wantsEmailFor(string $type): bool
    {
        if (!$this->email_notifications_enabled) {
            return false;
        }

        return match ($type) {
            'process_analysis' => $this->email_notify_process_analysis,
            'contract_analysis' => $this->email_notify_contract_analysis,
            default => false,
        };
    }

    public function judicialUsers(): HasMany
    {
        return $this->hasMany(JudicialUser::class);
    }

}
