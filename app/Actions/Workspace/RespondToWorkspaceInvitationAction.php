<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\NotificationCategory;
use App\Enums\WorkspaceInvitationStatus;
use App\Enums\WorkspaceRole;
use App\Exceptions\Workspace\AlreadyWorkspaceMemberException;
use App\Exceptions\Workspace\InvitationEmailMismatchException;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Services\Notification\UserNotifier;
use App\Support\Workspace\WorkspaceAudit;
use Illuminate\Support\Facades\DB;

/**
 * Accepts or declines an invitation. The caller proves it is theirs with the
 * token from the email, which works from any verified account (the link is the
 * credential, as with most email invitations), or by having verified the
 * invited address on their own account; see WorkspaceInvitationAccess for the
 * order of the answers. Either answer closes the invitation for good.
 *
 * An owner invitation is accepted only by the invited verified address, token
 * or not, and only within the acceptor's owned-team cap: a forwarded link must
 * not hand over a team.
 */
final class RespondToWorkspaceInvitationAction
{
    public function __construct(
        private readonly UserNotifier $notifier,
    ) {}

    public function accept(User $user, WorkspaceInvitation $invitation, ?string $token): Workspace
    {
        $byEmail = WorkspaceInvitationAccess::prove($user, $invitation, $token);

        if ($invitation->role === WorkspaceRole::Owner && ! $byEmail) {
            throw new InvitationEmailMismatchException;
        }

        return DB::transaction(function () use ($user, $invitation): Workspace {
            // The user row before the workspace row, the order every path keeps.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $workspace = WorkspaceOwnership::lock($invitation->workspace);

            /** @var WorkspaceInvitation $locked */
            $locked = WorkspaceInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            WorkspaceInvitationAccess::ensureOpen($locked);

            // Left open, so the address it was meant for can still use it.
            if (WorkspaceMember::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->exists()) {
                throw new AlreadyWorkspaceMemberException;
            }

            if ($locked->role === WorkspaceRole::Owner) {
                WorkspaceOwnership::ensureCanOwnAnotherTeam($user);
            }

            WorkspaceMember::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => $locked->role,
            ]);

            $locked->update(['status' => WorkspaceInvitationStatus::Accepted, 'closed_at' => now()]);

            WorkspaceAudit::record($user, 'workspace.member_joined', $workspace, [
                'invitation_id' => $locked->id,
                'member_user_id' => $user->id,
                'role' => $locked->role->value,
                'invited_by' => $locked->invited_by,
            ]);

            // Only owners invite, and an owner who loses the role takes their
            // pending invitations along, so the inviter is still an owner here.
            $this->notifier->send(
                $locked->invited_by === null ? null : User::query()->find($locked->invited_by),
                NotificationCategory::Workspace,
                'Undangan diterima',
                "{$user->name} bergabung ke {$workspace->name} sebagai {$locked->role->label()}.",
                invitationId: $locked->id,
                workspaceId: $workspace->id,
            );

            /** @var Workspace */
            return $user->workspaces()->withSummaryCounts()->whereKey($workspace->id)->firstOrFail();
        });
    }

    public function decline(User $user, WorkspaceInvitation $invitation, ?string $token): void
    {
        WorkspaceInvitationAccess::prove($user, $invitation, $token);

        DB::transaction(function () use ($user, $invitation): void {
            /** @var WorkspaceInvitation $locked */
            $locked = WorkspaceInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            WorkspaceInvitationAccess::ensureOpen($locked);

            $locked->update(['status' => WorkspaceInvitationStatus::Declined, 'closed_at' => now()]);

            WorkspaceAudit::record($user, 'workspace.invitation_declined', $invitation->workspace, [
                'invitation_id' => $locked->id,
                'role' => $locked->role->value,
            ]);
        });
    }
}
