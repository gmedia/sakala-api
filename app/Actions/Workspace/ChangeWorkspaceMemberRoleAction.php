<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\WorkspaceRole;
use App\Exceptions\Workspace\WorkspaceIsPersonalException;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\Workspace\WorkspaceAudit;
use Illuminate\Support\Facades\DB;

/** Owners change roles. Demoting the last owner is refused. */
final class ChangeWorkspaceMemberRoleAction
{
    public function handle(User $actor, Workspace $workspace, WorkspaceMember $member, WorkspaceRole $role): WorkspaceMember
    {
        if ($workspace->isPersonal()) {
            throw new WorkspaceIsPersonalException;
        }

        return DB::transaction(function () use ($actor, $workspace, $member, $role): WorkspaceMember {
            WorkspaceOwnership::lock($workspace);

            /** @var WorkspaceMember $locked */
            $locked = WorkspaceMember::query()->whereKey($member->id)->firstOrFail();
            $previous = $locked->role;

            if ($previous === $role) {
                return $locked;
            }

            if ($role !== WorkspaceRole::Owner) {
                WorkspaceOwnership::ensureAnotherOwner($workspace, $locked);
            }

            $locked->update(['role' => $role]);

            WorkspaceAudit::record($actor, 'workspace.member_role_changed', $workspace, [
                'member_user_id' => $locked->user_id,
                'from' => $previous->value,
                'to' => $role->value,
            ]);

            return $locked;
        });
    }
}
