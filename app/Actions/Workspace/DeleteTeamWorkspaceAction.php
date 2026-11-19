<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Actions\Project\PurgeDeletedProjectsAction;
use App\Exceptions\Workspace\WorkspaceIsPersonalException;
use App\Exceptions\Workspace\WorkspaceNotEmptyException;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspace\WorkspaceAudit;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a team that has no projects or managed databases left. The workspace row is locked, and
 * creating a project takes the same lock, so none can appear in between.
 * Soft-deleted projects keep waiting for the purge without a workspace, and
 * their secrets are erased now, as when an account is deleted. Members and
 * pending invitations go with the workspace.
 */
final class DeleteTeamWorkspaceAction
{
    public function __construct(
        private readonly PurgeDeletedProjectsAction $purge,
    ) {}

    public function handle(User $actor, Workspace $workspace): void
    {
        if ($workspace->isPersonal()) {
            throw new WorkspaceIsPersonalException;
        }

        DB::transaction(function () use ($actor, $workspace): void {
            Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();

            $projects = Project::query()->where('workspace_id', $workspace->id)->count();
            // A database on its way out does not hold the team: its row keeps
            // going without a workspace until the node drops it.
            $databases = ManagedDatabase::query()->where('workspace_id', $workspace->id)->holdingWorkspace()->count();

            if ($projects > 0 || $databases > 0) {
                throw new WorkspaceNotEmptyException($projects, $databases);
            }

            Project::onlyTrashed()->where('workspace_id', $workspace->id)->get()
                ->each(fn (Project $project) => $this->purge->eraseSecrets($project));
            Project::onlyTrashed()->where('workspace_id', $workspace->id)->update(['workspace_id' => null]);

            WorkspaceAudit::record($actor, 'workspace.deleted', $workspace);

            $workspace->delete();
        });
    }
}
