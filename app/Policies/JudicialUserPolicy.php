<?php

namespace App\Policies;

use App\Models\JudicialUser;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class JudicialUserPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JudicialUser $judicialUser): bool
    {
        return $user->id === $judicialUser->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, JudicialUser $judicialUser): bool
    {
        return $user->id === $judicialUser->user_id;
    }

    public function delete(User $user, JudicialUser $judicialUser): bool
    {
        return $user->id === $judicialUser->user_id;
    }

    public function restore(User $user, JudicialUser $judicialUser): bool
    {
        return $user->id === $judicialUser->user_id;
    }

    public function forceDelete(User $user, JudicialUser $judicialUser): bool
    {
        return $user->id === $judicialUser->user_id;
    }
}
