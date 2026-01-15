<?php

namespace App\Policies;

use App\Models\ContractAnalysis;
use App\Models\User;

class ContractAnalysisPolicy
{
    /**
     * Verifica se o usuário tem permissão para acessar análise de contratos
     */
    private function canAccessContractAnalysis(User $user): bool
    {
        return $user->hasRole(['Admin', 'Manager', 'Analista de Contrato']);
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->canAccessContractAnalysis($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ContractAnalysis $contractAnalysis): bool
    {
        // Pode ver se tem permissão E (é dono OU é Admin/Manager)
        if (!$this->canAccessContractAnalysis($user)) {
            return false;
        }

        return $user->id === $contractAnalysis->user_id || $user->hasRole(['Admin', 'Manager']);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->canAccessContractAnalysis($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ContractAnalysis $contractAnalysis): bool
    {
        if (!$this->canAccessContractAnalysis($user)) {
            return false;
        }

        return $user->id === $contractAnalysis->user_id || $user->hasRole(['Admin', 'Manager']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ContractAnalysis $contractAnalysis): bool
    {
        if (!$this->canAccessContractAnalysis($user)) {
            return false;
        }

        return $user->id === $contractAnalysis->user_id || $user->hasRole(['Admin', 'Manager']);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ContractAnalysis $contractAnalysis): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ContractAnalysis $contractAnalysis): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }
}
