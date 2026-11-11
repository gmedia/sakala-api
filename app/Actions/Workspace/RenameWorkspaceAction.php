<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Exceptions\Workspace\WorkspaceIsPersonalException;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspace\WorkspaceAudit;
use Illuminate\Support\Facades\DB;

/** Renames a team. The slug stays, so links keep working. */
final class RenameWorkspaceAction
{
    public function handle(User $actor, Workspace $workspace, string $name): Workspace
    {
        if ($workspace->isPersonal()) {
            throw new WorkspaceIsPersonalException;
        }

        return DB::transaction(function () use ($actor, $workspace, $name): Workspace {
            $previous = $workspace->name;

            if ($previous !== $name) {
                $workspace->update(['name' => $name]);
                WorkspaceAudit::record($actor, 'workspace.renamed', $workspace);
            }

            return $workspace;
        });
    }
}
