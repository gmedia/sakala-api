<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\WorkspaceRole;
use App\Exceptions\Workspace\LastWorkspaceOwnerException;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

/**
 * The rule every membership change keeps: a team always has an owner. Callers
 * hold the workspace row lock, so two owners cannot leave at the same time.
 */
final class WorkspaceOwnership
{
    public static function lock(Workspace $workspace): Workspace
    {
        /** @var Workspace */
        return Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
    }

    /** Throws when $member is the only owner and is about to stop being one. */
    public static function ensureAnotherOwner(Workspace $workspace, WorkspaceMember $member): void
    {
        if ($member->role !== WorkspaceRole::Owner) {
            return;
        }

        $otherOwners = WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('role', WorkspaceRole::Owner)
            ->whereKeyNot($member->id)
            ->exists();

        if (! $otherOwners) {
            throw new LastWorkspaceOwnerException(collect([$workspace]));
        }
    }
}
