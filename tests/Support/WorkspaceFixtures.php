<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

/**
 * A team owned by $owner, with each further user added in the given role.
 *
 * @param  array<int, array{0: User, 1: WorkspaceRole}>  $members
 */
function teamWorkspace(User $owner, array $members = [], array $attributes = []): Workspace
{
    $workspace = Workspace::factory()->ownedBy($owner)->create($attributes);

    foreach ($members as [$user, $role]) {
        addWorkspaceMember($workspace, $user, $role);
    }

    return $workspace;
}

function addWorkspaceMember(Workspace $workspace, User $user, WorkspaceRole $role): WorkspaceMember
{
    return WorkspaceMember::query()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
    ]);
}
