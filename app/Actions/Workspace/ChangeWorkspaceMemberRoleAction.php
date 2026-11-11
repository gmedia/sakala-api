<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\NotificationCategory;
use App\Enums\WorkspaceRole;
use App\Exceptions\Workspace\WorkspaceIsPersonalException;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Notification\UserNotifier;
use App\Support\Workspace\WorkspaceAudit;
use Illuminate\Support\Facades\DB;

/**
 * Owners change roles. Demoting the last owner is refused, promoting someone
 * to owner respects their owned-team cap, and an owner who is demoted loses
 * the invitations they sent. The member gets a `workspace` notification unless
 * they changed their own role.
 */
final class ChangeWorkspaceMemberRoleAction
{
    public function __construct(
        private readonly UserNotifier $notifier,
    ) {}

    public function handle(User $actor, Workspace $workspace, WorkspaceMember $member, WorkspaceRole $role): WorkspaceMember
    {
        if ($workspace->isPersonal()) {
            throw new WorkspaceIsPersonalException;
        }

        return DB::transaction(function () use ($actor, $workspace, $member, $role): WorkspaceMember {
            // The user row before the workspace row, the order every path keeps.
            if ($role === WorkspaceRole::Owner) {
                User::query()->whereKey($member->user_id)->lockForUpdate()->firstOrFail();
            }

            WorkspaceOwnership::lock($workspace);

            /** @var WorkspaceMember $locked */
            $locked = WorkspaceMember::query()->whereKey($member->id)->firstOrFail();
            $previous = $locked->role;

            if ($previous === $role) {
                return $locked;
            }

            if ($role !== WorkspaceRole::Owner) {
                WorkspaceOwnership::ensureAnotherOwner($workspace, $locked);
            } else {
                /** @var User $target */
                $target = User::query()->whereKey($locked->user_id)->firstOrFail();
                WorkspaceOwnership::ensureCanOwnAnotherTeam($target, actingForSelf: $target->id === $actor->id);
            }

            $locked->update(['role' => $role]);

            if ($previous === WorkspaceRole::Owner) {
                WorkspaceOwnership::revokeInvitationsSentBy($actor, $locked->user_id, reason: 'inviter_lost_role', workspace: $workspace);
            }

            WorkspaceAudit::record($actor, 'workspace.member_role_changed', $workspace, [
                'member_user_id' => $locked->user_id,
                'from' => $previous->value,
                'to' => $role->value,
            ]);

            if ($locked->user_id !== $actor->id) {
                $this->notifier->send(
                    User::query()->find($locked->user_id),
                    NotificationCategory::Workspace,
                    'Peranmu di tim berubah',
                    "{$actor->name} mengubah peranmu di {$workspace->name} dari {$previous->label()} menjadi {$role->label()}.",
                    workspaceId: $workspace->id,
                );
            }

            return $locked;
        });
    }
}
