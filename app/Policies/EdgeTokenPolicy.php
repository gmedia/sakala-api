<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

final class EdgeTokenPolicy
{
    /**
     * Determine whether the user can list Edge instances and their health.
     */
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
