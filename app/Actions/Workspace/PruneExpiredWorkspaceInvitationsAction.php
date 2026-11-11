<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Models\WorkspaceInvitation;

/**
 * Expired invitations hold an address that may belong to nobody on Sakala;
 * they are useless once expired, so they do not wait.
 */
final class PruneExpiredWorkspaceInvitationsAction
{
    public function handle(): int
    {
        return WorkspaceInvitation::query()->where('expires_at', '<', now())->delete();
    }
}
