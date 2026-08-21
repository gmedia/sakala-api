<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Agent;
use App\Models\User;

final class AgentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Agent $agent): bool
    {
        return $user->role === UserRole::Admin;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Agent $agent): bool
    {
        return $user->role === UserRole::Admin;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Agent $agent): bool
    {
        return $user->role === UserRole::Admin;
    }
}
