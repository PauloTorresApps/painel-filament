<?php

namespace App\Policies;

use App\Models\JudicialUser;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class JudicialUserPolicy
{
    /**
     * Verifica se o usuário é Admin ou Manager
     */
    private function isAdminOrManager(User $user): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JudicialUser $judicialUser): bool
    {
        return $this->isAdminOrManager($user) || $user->id === $judicialUser->user_id;
    }

    public function create(User $user): bool
    {
        // Admin/Manager não podem ter usuários judiciais vinculados a si mesmos
        // mas podem criar para outros usuários
        return true;
    }

    public function update(User $user, JudicialUser $judicialUser): bool
    {
        return $this->isAdminOrManager($user) || $user->id === $judicialUser->user_id;
    }

    public function delete(User $user, JudicialUser $judicialUser): bool
    {
        return $this->isAdminOrManager($user) || $user->id === $judicialUser->user_id;
    }

    public function restore(User $user, JudicialUser $judicialUser): bool
    {
        return $this->isAdminOrManager($user) || $user->id === $judicialUser->user_id;
    }

    public function forceDelete(User $user, JudicialUser $judicialUser): bool
    {
        return $this->isAdminOrManager($user) || $user->id === $judicialUser->user_id;
    }
}
