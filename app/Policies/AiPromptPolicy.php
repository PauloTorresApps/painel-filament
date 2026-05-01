<?php

namespace App\Policies;

use App\Models\AiPrompt;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AiPromptPolicy
{
    /**
     * Verifica se o usuário é Admin ou Manager
     */
    private function isAdminOrManager(User $user): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->isAdminOrManager($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, AiPrompt $aiPrompt): bool
    {
        return $this->isAdminOrManager($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->isAdminOrManager($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, AiPrompt $aiPrompt): bool
    {
        return $this->isAdminOrManager($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AiPrompt $aiPrompt): bool
    {
        return $this->isAdminOrManager($user);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, AiPrompt $aiPrompt): bool
    {
        return $this->isAdminOrManager($user);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, AiPrompt $aiPrompt): bool
    {
        return $this->isAdminOrManager($user);
    }
}
