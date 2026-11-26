<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\ManagedValkey;
use App\Models\User;
use App\Models\Workspace;

/**
 * Managed Valkey stores follow the workspace roles, like databases and
 * buckets: any member reads them, owners and members create, rename,
 * resize, delete, attach, detach, and rotate the password. Only owners read
 * the password, as for databases and buckets. Admins keep every power.
 * Everyone else gets the generic 403.
 */
final class ManagedValkeyPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $workspace->roleOf($user) !== null;
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->roleOf($user)?->managesProjects() === true;
    }

    public function view(User $user, ManagedValkey $valkey): bool
    {
        return $this->roleOf($user, $valkey) !== null;
    }

    /** Rename, resize, attach, detach, and rotate the password. */
    public function update(User $user, ManagedValkey $valkey): bool
    {
        return $this->roleOf($user, $valkey)?->managesProjects() === true;
    }

    public function delete(User $user, ManagedValkey $valkey): bool
    {
        return $this->update($user, $valkey);
    }

    public function viewCredentials(User $user, ManagedValkey $valkey): bool
    {
        return $this->roleOf($user, $valkey) === WorkspaceRole::Owner;
    }

    private function roleOf(User $user, ManagedValkey $valkey): ?WorkspaceRole
    {
        return $valkey->workspace?->roleOf($user);
    }
}
