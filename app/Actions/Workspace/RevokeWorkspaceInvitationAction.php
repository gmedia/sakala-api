<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\WorkspaceInvitationStatus;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Support\Workspace\WorkspaceAudit;
use Illuminate\Support\Facades\DB;

/**
 * The link in the email stops working at once and answers INVITATION_REVOKED.
 * An invitation that is no longer pending is not there to revoke: 404.
 */
final class RevokeWorkspaceInvitationAction
{
    public function handle(User $actor, WorkspaceInvitation $invitation): void
    {
        DB::transaction(function () use ($actor, $invitation): void {
            $closed = WorkspaceInvitation::query()
                ->pending()
                ->whereKey($invitation->id)
                ->update(['status' => WorkspaceInvitationStatus::Revoked, 'closed_at' => now()]);

            if ($closed === 0) {
                WorkspaceInvitationAccess::throwNotFound($invitation);
            }

            WorkspaceAudit::record($actor, 'workspace.invitation_revoked', $invitation->workspace, [
                'invitation_id' => $invitation->id,
                'role' => $invitation->role->value,
            ]);
        });
    }
}
