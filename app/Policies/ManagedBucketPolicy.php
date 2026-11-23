<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\ManagedBucket;
use App\Models\User;
use App\Models\Workspace;

/**
 * Managed buckets follow the workspace roles, like databases: any member
 * reads them, owners and members create, rename, resize, delete, attach,
 * detach, and rotate the key. Only owners read the secret: the key works
 * from the internet, so it stays off screens other than the owner's. Admins
 * keep every power. Everyone else gets the generic 403.
 */
final class ManagedBucketPolicy
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

    public function view(User $user, ManagedBucket $bucket): bool
    {
        return $this->roleOf($user, $bucket) !== null;
    }

    /** Rename, resize, attach, detach, and rotate the key. */
    public function update(User $user, ManagedBucket $bucket): bool
    {
        return $this->roleOf($user, $bucket)?->managesProjects() === true;
    }

    public function delete(User $user, ManagedBucket $bucket): bool
    {
        return $this->update($user, $bucket);
    }

    public function viewCredentials(User $user, ManagedBucket $bucket): bool
    {
        return $this->roleOf($user, $bucket) === WorkspaceRole::Owner;
    }

    private function roleOf(User $user, ManagedBucket $bucket): ?WorkspaceRole
    {
        return $bucket->workspace?->roleOf($user);
    }
}
