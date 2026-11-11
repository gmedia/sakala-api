<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

/**
 * Members read the workspace and its member list; owners manage members,
 * invitations, and the workspace; owners and members create projects in it.
 * Rules that depend on the workspace being a team (rename, delete, invite,
 * leave) are answered by the actions with WORKSPACE_IS_PERSONAL, so the owner
 * of a personal workspace learns why. Non-members get the generic 403.
 *
 * No admin bypass: admin management of workspaces belongs to the admin API.
 */
final class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $workspace->roleOf($user) !== null;
    }

    public function createProject(User $user, Workspace $workspace): bool
    {
        return $workspace->roleOf($user)?->managesProjects() === true;
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $workspace->roleOf($user) === WorkspaceRole::Owner;
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->update($user, $workspace);
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $this->update($user, $workspace);
    }

    public function leave(User $user, Workspace $workspace): bool
    {
        return $this->view($user, $workspace);
    }
}
