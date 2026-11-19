<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Exceptions\Database\DatabaseCrossRegionUnsupportedException;
use App\Exceptions\Database\ProjectDatabaseAlreadyAttachedException;
use App\Models\DatabaseAttachment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\AgentNodeSchedulerService;
use App\Support\Database\DatabaseAudit;

/**
 * The rules and writes shared by every path that attaches a database to a
 * project: attaching an existing database and creating one for a project.
 * Callers hold the project row lock, and the database row lock or a database
 * they just created, inside their transaction.
 */
final class ProjectDatabaseAttacher
{
    public function __construct(
        private readonly AgentNodeSchedulerService $scheduler,
    ) {}

    /** The project's attachment, at most one (unique `database_attachments.project_id`). */
    public function attachmentOf(Project $lockedProject): ?DatabaseAttachment
    {
        return DatabaseAttachment::query()->where('project_id', $lockedProject->id)->first();
    }

    /**
     * One database per project in this phase: its variables have fixed names.
     *
     * @throws ProjectDatabaseAlreadyAttachedException
     */
    public function ensureNoneAttached(?DatabaseAttachment $existing): void
    {
        if ($existing !== null) {
            throw new ProjectDatabaseAlreadyAttachedException($existing->database()->withTrashed()->firstOrFail());
        }
    }

    /**
     * Until a private network joins regions, a project only reaches databases
     * of its own region. Either region unknown means any region, which cannot
     * conflict.
     *
     * @throws DatabaseCrossRegionUnsupportedException
     */
    public function ensureSameRegion(?string $databaseRegion, Project $project): void
    {
        $projectRegion = $this->scheduler->regionOf($project);

        if ($databaseRegion !== null && $projectRegion !== null && $databaseRegion !== $projectRegion) {
            throw new DatabaseCrossRegionUnsupportedException($databaseRegion, $projectRegion);
        }
    }

    /**
     * Attach, so the project's next deployment receives the connection
     * variables, and record it. With `$deployRequester`, the project is also
     * deployed as them once the database is ready (DeployAfterDatabaseReadyAction).
     */
    public function attach(User $actor, ManagedDatabase $database, Project $lockedProject, ?User $deployRequester = null): DatabaseAttachment
    {
        $attachment = DatabaseAttachment::query()->create([
            'database_id' => $database->id,
            'project_id' => $lockedProject->id,
            'created_by' => $actor->id,
            ...($deployRequester === null ? [] : [
                'deploy_requested_by' => $deployRequester->id,
                'deploy_requested_at' => now(),
            ]),
        ]);

        $lockedProject->refreshDatabaseFingerprint();
        $lockedProject->save();

        DatabaseAudit::record($actor, DatabaseAudit::ATTACHED, $database, ['project_id' => $lockedProject->id]);

        if ($deployRequester !== null) {
            DatabaseAudit::record($deployRequester, DatabaseAudit::PROJECT_DEPLOY_REQUESTED, $database, [
                'project_id' => $lockedProject->id,
                'attachment_id' => $attachment->id,
            ]);
        }

        return $attachment;
    }
}
