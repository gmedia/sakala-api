<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Workspace\PruneExpiredWorkspaceInvitationsAction;
use Illuminate\Console\Command;

final class PruneWorkspaceInvitationsCommand extends Command
{
    protected $signature = 'workspaces:prune-invitations';

    protected $description = 'Delete workspace invitations past their expiry and retention window';

    public function handle(PruneExpiredWorkspaceInvitationsAction $action): int
    {
        $this->info(sprintf('Pruned %d workspace invitations.', $action->handle()));

        return self::SUCCESS;
    }
}
