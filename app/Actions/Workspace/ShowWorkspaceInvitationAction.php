<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Data\Workspace\WorkspaceInvitationDetailsData;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;

/**
 * Reads an invitation for whoever holds its link, under the same proof and the
 * same answers as accepting (see WorkspaceInvitationAccess), so the Console can
 * show it even when it is addressed to another email. Whether this account may
 * accept it comes as the code accepting would answer; the invited address is
 * never part of it.
 */
final class ShowWorkspaceInvitationAction
{
    public function handle(User $user, WorkspaceInvitation $invitation, ?string $token): WorkspaceInvitationDetailsData
    {
        $byEmail = WorkspaceInvitationAccess::prove($user, $invitation, $token);

        return new WorkspaceInvitationDetailsData(
            invitation: $invitation->loadMissing(['workspace', 'inviter']),
            accept_error_code: $this->acceptErrorCode($user, $invitation, $byEmail),
        );
    }

    private function acceptErrorCode(User $user, WorkspaceInvitation $invitation, bool $byEmail): ?string
    {
        $owner = $invitation->role === WorkspaceRole::Owner;

        if ($owner && ! $byEmail) {
            return 'INVITATION_EMAIL_MISMATCH';
        }

        if (WorkspaceMember::query()->where('workspace_id', $invitation->workspace_id)->where('user_id', $user->id)->exists()) {
            return 'ALREADY_WORKSPACE_MEMBER';
        }

        if ($owner && ! WorkspaceOwnership::canOwnAnotherTeam($user)) {
            return 'TEAM_LIMIT_EXCEEDED';
        }

        return null;
    }
}
