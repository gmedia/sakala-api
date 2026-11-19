<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Enums\DatabaseStatus;
use App\Exceptions\Database\DatabaseCrossRegionUnsupportedException;
use App\Exceptions\Database\DatabaseLockedException;
use App\Exceptions\Database\DatabaseNotReadyException;
use App\Exceptions\Database\ProjectDatabaseAlreadyAttachedException;
use App\Models\DatabaseAttachment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\AgentNodeSchedulerService;
use App\Support\Database\DatabaseAudit;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Attaches a database to a project of the same workspace. The project's next
 * deployment receives the connection variables, so the project's database
 * fingerprint changes and `redeploy_required` turns true. Attaching again is
 * a no-op. The database row is locked before the project row, the order every
 * database path takes.
 */
final class AttachDatabaseAction
{
    public function __construct(
        private readonly AgentNodeSchedulerService $scheduler,
    ) {}

    /**
     * @return array{attachment: DatabaseAttachment, created: bool}
     *
     * @throws DatabaseLockedException
     * @throws DatabaseNotReadyException
     * @throws ProjectDatabaseAlreadyAttachedException
     * @throws DatabaseCrossRegionUnsupportedException
     */
    public function handle(User $actor, ManagedDatabase $database, Project $project): array
    {
        if ($project->workspace_id === null || $project->workspace_id !== $database->workspace_id) {
            throw new InvalidArgumentException('A database attaches only to projects of its own workspace.');
        }

        return DB::transaction(function () use ($actor, $database, $project): array {
            /** @var ManagedDatabase $lockedDatabase */
            $lockedDatabase = ManagedDatabase::query()->whereKey($database->id)->lockForUpdate()->firstOrFail();
            /** @var Project $lockedProject */
            $lockedProject = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            $existing = DatabaseAttachment::query()->where('project_id', $lockedProject->id)->first();

            if ($existing?->database_id === $lockedDatabase->id) {
                return ['attachment' => $existing, 'created' => false];
            }

            if ($lockedDatabase->status === DatabaseStatus::Locked) {
                throw new DatabaseLockedException;
            }

            if (! $lockedDatabase->status->isProvisioned()) {
                throw new DatabaseNotReadyException($lockedDatabase->status);
            }

            if ($existing !== null) {
                throw new ProjectDatabaseAlreadyAttachedException($existing->database()->withTrashed()->firstOrFail());
            }

            $this->ensureSameRegion($lockedDatabase, $lockedProject);

            $attachment = DatabaseAttachment::query()->create([
                'database_id' => $lockedDatabase->id,
                'project_id' => $lockedProject->id,
                'created_by' => $actor->id,
            ]);

            $lockedProject->refreshDatabaseFingerprint();
            $lockedProject->save();

            DatabaseAudit::record($actor, DatabaseAudit::ATTACHED, $lockedDatabase, ['project_id' => $lockedProject->id]);

            return ['attachment' => $attachment, 'created' => true];
        });
    }

    /**
     * Until a private network joins regions, a project only reaches databases
     * of its own region. Either region unknown means any region, which cannot
     * conflict.
     *
     * @throws DatabaseCrossRegionUnsupportedException
     */
    private function ensureSameRegion(ManagedDatabase $database, Project $project): void
    {
        $projectRegion = $this->scheduler->regionOf($project);

        if ($database->region !== null && $projectRegion !== null && $database->region !== $projectRegion) {
            throw new DatabaseCrossRegionUnsupportedException($database->region, $projectRegion);
        }
    }
}
