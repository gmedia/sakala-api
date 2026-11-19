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
use App\Services\Database\ProjectDatabaseAttacher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Attaches a database to a project of the same workspace. The project's next
 * deployment receives the connection variables, so the project's database
 * fingerprint changes and `redeploy_required` turns true. Attaching again is
 * a no-op. The database row is locked before the project row, the order every
 * database path takes. Only a ready database attaches here; a database still
 * being created is attached only by CreateDatabaseAction, for its project.
 */
final class AttachDatabaseAction
{
    public function __construct(
        private readonly ProjectDatabaseAttacher $attacher,
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

            $existing = $this->attacher->attachmentOf($lockedProject);

            if ($existing?->database_id === $lockedDatabase->id) {
                return ['attachment' => $existing, 'created' => false];
            }

            if ($lockedDatabase->status === DatabaseStatus::Locked) {
                throw new DatabaseLockedException;
            }

            if (! $lockedDatabase->status->isProvisioned()) {
                throw new DatabaseNotReadyException($lockedDatabase->status);
            }

            $this->attacher->ensureNoneAttached($existing);
            $this->attacher->ensureSameRegion($lockedDatabase->region, $lockedProject);

            $attachment = $this->attacher->attach($actor, $lockedDatabase, $lockedProject);

            return ['attachment' => $attachment, 'created' => true];
        });
    }
}
