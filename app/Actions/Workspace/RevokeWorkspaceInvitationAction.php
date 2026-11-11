<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Support\Workspace\WorkspaceAudit;
use Illuminate\Support\Facades\DB;

/** The link in the email stops working at once. */
final class RevokeWorkspaceInvitationAction
{
    public function handle(User $actor, WorkspaceInvitation $invitation): void
    {
        DB::transaction(function () use ($actor, $invitation): void {
            $invitation->delete();

            WorkspaceAudit::record($actor, 'workspace.invitation_revoked', $invitation->workspace, [
                'invitation_id' => $invitation->id,
                'role' => $invitation->role->value,
            ]);
        });
    }
}
