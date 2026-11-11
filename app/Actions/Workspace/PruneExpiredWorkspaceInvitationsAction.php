<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Models\WorkspaceInvitation;

/**
 * Invitations hold an address that may belong to nobody on Sakala, so none
 * outlives its expiry by more than the retention window. Until then the link
 * can still tell its holder that the invitation expired, was answered, or was
 * revoked; answered and revoked ones are never kept longer than an untouched
 * one would be.
 */
final class PruneExpiredWorkspaceInvitationsAction
{
    public function handle(): int
    {
        $retention = max(0, (int) config('sakala.workspaces.invitation_retention_hours', 168));

        return WorkspaceInvitation::query()->where('expires_at', '<', now()->subHours($retention))->delete();
    }
}
