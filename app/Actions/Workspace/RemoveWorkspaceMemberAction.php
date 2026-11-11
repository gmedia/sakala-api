<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Exceptions\Workspace\WorkspaceIsPersonalException;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\Workspace\WorkspaceAudit;
use Illuminate\Support\Facades\DB;

/**
 * Removes a member, or the caller when leaving. Access ends with the row: every
 * request checks membership afresh. The last owner can be neither removed nor
 * leave. Projects stay with the workspace.
 */
final class RemoveWorkspaceMemberAction
{
    public function handle(User $actor, Workspace $workspace, WorkspaceMember $member): void
    {
        if ($workspace->isPersonal()) {
            throw new WorkspaceIsPersonalException;
        }

        DB::transaction(function () use ($actor, $workspace, $member): void {
            WorkspaceOwnership::lock($workspace);

            /** @var WorkspaceMember $locked */
            $locked = WorkspaceMember::query()->whereKey($member->id)->firstOrFail();

            WorkspaceOwnership::ensureAnotherOwner($workspace, $locked);

            $locked->delete();

            $leaving = $locked->user_id === $actor->id;

            WorkspaceAudit::record($actor, $leaving ? 'workspace.member_left' : 'workspace.member_removed', $workspace, [
                'member_user_id' => $locked->user_id,
                'role' => $locked->role->value,
            ]);
        });
    }
}
