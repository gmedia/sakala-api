<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\ManagedDatabase;
use App\Models\User;
use App\Models\Workspace;

/**
 * Managed databases follow the workspace roles, like projects: any member
 * reads them, owners and members create, rename, delete, attach, detach, and
 * rotate the password. Only owners read the credentials: members still manage
 * attachments, and the connection variables reach the code they deploy, so
 * this keeps the password off screens rather than out of reach. Admins keep
 * every power, as with projects. Everyone else gets the generic 403.
 */
final class ManagedDatabasePolicy
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

    public function view(User $user, ManagedDatabase $database): bool
    {
        return $this->roleOf($user, $database) !== null;
    }

    /** Rename, attach, detach, and rotate the password. */
    public function update(User $user, ManagedDatabase $database): bool
    {
        return $this->roleOf($user, $database)?->managesProjects() === true;
    }

    public function delete(User $user, ManagedDatabase $database): bool
    {
        return $this->update($user, $database);
    }

    public function viewCredentials(User $user, ManagedDatabase $database): bool
    {
        return $this->roleOf($user, $database) === WorkspaceRole::Owner;
    }

    private function roleOf(User $user, ManagedDatabase $database): ?WorkspaceRole
    {
        return $database->workspace?->roleOf($user);
    }
}
