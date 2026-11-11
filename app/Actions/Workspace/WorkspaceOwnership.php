<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\WorkspaceInvitationStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceType;
use App\Exceptions\Workspace\LastWorkspaceOwnerException;
use App\Exceptions\Workspace\TeamLimitExceededException;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Support\Workspace\WorkspaceAudit;

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

    /**
     * Refuses when $user would own more teams than allowed. Every path to
     * ownership calls it: creating a team, accepting an owner invitation, and
     * being promoted. The user row is locked first, inside the caller's
     * transaction and before any workspace row, so two such paths for the
     * same user are counted one at a time. Admins are not capped.
     */
    public static function ensureCanOwnAnotherTeam(User $user, bool $actingForSelf = true): void
    {
        /** @var User $locked */
        $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

        if ($locked->isAdmin()) {
            return;
        }

        $owned = self::ownedTeamCount($locked);

        if ($owned >= $locked->maxOwnedTeams()) {
            throw new TeamLimitExceededException($locked->maxOwnedTeams(), $owned, $actingForSelf);
        }
    }

    /**
     * The same rule without the lock, for showing whether an owner invitation
     * could be accepted. Accepting checks again under the lock.
     */
    public static function canOwnAnotherTeam(User $user): bool
    {
        return $user->isAdmin() || self::ownedTeamCount($user) < $user->maxOwnedTeams();
    }

    private static function ownedTeamCount(User $user): int
    {
        return WorkspaceMember::query()
            ->where('user_id', $user->id)
            ->where('role', WorkspaceRole::Owner)
            ->whereHas('workspace', fn ($query) => $query->where('type', WorkspaceType::Team))
            ->count();
    }

    /**
     * Only owners invite, so an owner who leaves, is removed, is demoted, or
     * deletes the account takes their pending invitations along. Null
     * $workspace means every workspace.
     */
    public static function revokeInvitationsSentBy(?User $actor, int $inviterId, string $reason, ?Workspace $workspace = null): void
    {
        WorkspaceInvitation::query()
            ->with('workspace')
            ->pending()
            ->where('invited_by', $inviterId)
            ->when($workspace !== null, fn ($query) => $query->where('workspace_id', $workspace?->id))
            ->get()
            ->each(function (WorkspaceInvitation $invitation) use ($actor, $reason): void {
                $invitation->update(['status' => WorkspaceInvitationStatus::Revoked, 'closed_at' => now()]);

                WorkspaceAudit::record($actor, 'workspace.invitation_revoked', $invitation->workspace, [
                    'invitation_id' => $invitation->id,
                    'role' => $invitation->role->value,
                    'reason' => $reason,
                ]);
            });
    }
}
