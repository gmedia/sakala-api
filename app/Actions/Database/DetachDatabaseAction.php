<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Models\DatabaseAttachment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\User;
use App\Support\Database\DatabaseAudit;
use Illuminate\Support\Facades\DB;

/**
 * Detaches a database from a project. The data stays; the project's next
 * deployment no longer receives the connection variables, so
 * `redeploy_required` turns true while the running one still has them.
 */
final class DetachDatabaseAction
{
    /** Audit `reason` when deleting the project detached it. */
    public const REASON_PROJECT_DELETED = 'project_deleted';

    public function handle(User $actor, ManagedDatabase $database, Project $project, ?string $reason = null): void
    {
        DB::transaction(function () use ($actor, $database, $project, $reason): void {
            ManagedDatabase::withTrashed()->whereKey($database->id)->lockForUpdate()->firstOrFail();
            /** @var Project $lockedProject */
            $lockedProject = Project::withTrashed()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            DatabaseAttachment::query()
                ->where('database_id', $database->id)
                ->where('project_id', $lockedProject->id)
                ->firstOrFail()
                ->delete();

            $lockedProject->refreshDatabaseFingerprint();
            $lockedProject->save();

            DatabaseAudit::record($actor, DatabaseAudit::DETACHED, $database, array_filter([
                'project_id' => $lockedProject->id,
                'reason' => $reason,
            ], static fn (?string $value): bool => $value !== null));
        });
    }
}
