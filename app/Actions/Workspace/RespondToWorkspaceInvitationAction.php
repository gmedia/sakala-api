<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\WorkspaceRole;
use App\Exceptions\Workspace\AlreadyWorkspaceMemberException;
use App\Exceptions\Workspace\EmailVerificationRequiredException;
use App\Exceptions\Workspace\InvitationEmailMismatchException;
use App\Exceptions\Workspace\InvitationExpiredException;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Support\Workspace\WorkspaceAudit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Accepts or declines an invitation. The caller proves it is theirs with the
 * token from the email, which works from any verified account (the link is the
 * credential, as with most email invitations), or by having verified the
 * invited address on their own account. Anything else is a plain 404, so an ID
 * reveals nothing. Either answer uses the invitation up.
 *
 * An owner invitation is accepted only by the invited verified address, token
 * or not, and only within the acceptor's owned-team cap: a forwarded link must
 * not hand over a team.
 */
final class RespondToWorkspaceInvitationAction
{
    public function accept(User $user, WorkspaceInvitation $invitation, ?string $token): Workspace
    {
        $this->authorise($user, $invitation, $token, accepting: true);

        return DB::transaction(function () use ($user, $invitation): Workspace {
            // The user row before the workspace row, the order every path keeps.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $workspace = WorkspaceOwnership::lock($invitation->workspace);

            /** @var WorkspaceInvitation $locked */
            $locked = WorkspaceInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            if ($locked->isExpired()) {
                throw new InvitationExpiredException;
            }

            // Kept, so the address it was meant for can still use it.
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

            $locked->delete();

            WorkspaceAudit::record($user, 'workspace.member_joined', $workspace, [
                'invitation_id' => $locked->id,
                'member_user_id' => $user->id,
                'role' => $locked->role->value,
                'invited_by' => $locked->invited_by,
            ]);

            /** @var Workspace */
            return $user->workspaces()->whereKey($workspace->id)->firstOrFail();
        });
    }

    public function decline(User $user, WorkspaceInvitation $invitation, ?string $token): void
    {
        $this->authorise($user, $invitation, $token, accepting: false);

        DB::transaction(function () use ($user, $invitation): void {
            $deleted = WorkspaceInvitation::query()->whereKey($invitation->id)->delete();

            if ($deleted === 0) {
                throw (new ModelNotFoundException)->setModel(WorkspaceInvitation::class);
            }

            WorkspaceAudit::record($user, 'workspace.invitation_declined', $invitation->workspace, [
                'invitation_id' => $invitation->id,
                'role' => $invitation->role->value,
            ]);
        });
    }

    private function authorise(User $user, WorkspaceInvitation $invitation, ?string $token, bool $accepting): void
    {
        $byToken = $token !== null && $token !== ''
            && hash_equals($invitation->token_hash, WorkspaceInvitation::hashToken($token));
        $byEmail = $user->hasVerifiedEmail() && Str::lower($user->email) === $invitation->email;

        if (! $byToken && ! $byEmail) {
            throw (new ModelNotFoundException)->setModel(WorkspaceInvitation::class);
        }

        if (! $user->hasVerifiedEmail()) {
            throw new EmailVerificationRequiredException;
        }

        if ($accepting && $invitation->role === WorkspaceRole::Owner && ! $byEmail) {
            throw new InvitationEmailMismatchException;
        }
    }
}
