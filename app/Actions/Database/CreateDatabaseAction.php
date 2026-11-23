<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Data\Database\CreateDatabaseData;
use App\Enums\DatabaseEngine;
use App\Enums\DatabaseStatus;
use App\Exceptions\Database\DatabaseCrossRegionUnsupportedException;
use App\Exceptions\Database\DatabaseLimitExceededException;
use App\Exceptions\Database\DatabaseRegionUnavailableException;
use App\Exceptions\Database\DatabaseStorageLimitExceededException;
use App\Exceptions\Database\DatabaseUnavailableException;
use App\Exceptions\Database\ProjectDatabaseAlreadyAttachedException;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Agent\AgentNodeSchedulerService;
use App\Services\Database\DatabaseCommandService;
use App\Services\Database\ProjectDatabaseAttacher;
use App\Services\Runtime\PilotRuntimeLimitService;
use App\Support\Database\DatabaseAudit;
use App\Support\Database\DatabaseSize;
use App\Support\Naming\UniqueDisplayName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates a managed database in a workspace: random names and password, a
 * database node of the region, and the CreateDatabase command for it. The
 * database is `provisioning` until the node reports. Quotas are checked under
 * the workspace row lock, so two requests cannot both take the last slot;
 * admins acting are not limited, as with projects.
 *
 * With a project, the database is attached to it in the same transaction, the
 * only way a database that is still being created gets attached, and the
 * project may ask to be deployed once the database is ready. Locks follow the
 * workspace, then the project, as deployments take them; the new database
 * row is invisible to everyone else until commit.
 */
final class CreateDatabaseAction
{
    public function __construct(
        private readonly PilotRuntimeLimitService $limits,
        private readonly AgentNodeSchedulerService $scheduler,
        private readonly DatabaseCommandService $commands,
        private readonly ProjectDatabaseAttacher $attacher,
    ) {}

    /**
     * @throws DatabaseUnavailableException
     * @throws ProjectDatabaseAlreadyAttachedException
     * @throws DatabaseLimitExceededException
     * @throws DatabaseStorageLimitExceededException
     * @throws DatabaseCrossRegionUnsupportedException
     * @throws DatabaseRegionUnavailableException
     */
    public function handle(User $actor, Workspace $workspace, CreateDatabaseData $data): ManagedDatabase
    {
        $project = $data->project;

        if ($project !== null && $project->workspace_id !== $workspace->id) {
            throw new InvalidArgumentException('A database is created only for projects of its own workspace.');
        }

        if ($project !== null && ! $this->scheduler->databasesAvailable()) {
            throw new DatabaseUnavailableException;
        }

        return DB::transaction(function () use ($actor, $workspace, $data, $project): ManagedDatabase {
            /** @var Workspace $locked */
            $locked = Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            /** @var Project|null $lockedProject */
            $lockedProject = $project === null ? null : Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            if ($lockedProject !== null) {
                $this->attacher->ensureNoneAttached($this->attacher->attachmentOf($lockedProject));
            }

            if (! $actor->isAdmin()) {
                $this->checkLimits($locked);
            }

            $name = $this->nameFor($locked, $data->name, $lockedProject);
            $region = $data->region;

            if ($lockedProject !== null) {
                $this->attacher->ensureSameRegion($region, $lockedProject);
                $region ??= $this->scheduler->regionOf($lockedProject);
            }

            $node = $this->scheduler->selectDatabaseNode($region ?? $this->limits->defaultRegionOf($locked));
            $pgDatabase = $this->newIdentifier();
            $pgRole = $this->newIdentifier();
            $password = Str::random(ManagedDatabase::PASSWORD_LENGTH);

            $database = ManagedDatabase::query()->create([
                'workspace_id' => $locked->id,
                'name' => $name,
                'engine' => DatabaseEngine::PostgreSql,
                'region' => $node->effectiveRegion(),
                'status' => DatabaseStatus::Provisioning,
                'node_id' => $node->id,
                'pg_database' => $pgDatabase,
                'pg_role' => $pgRole,
                'password' => $password,
                'created_by' => $actor->id,
            ]);

            $command = $this->commands->create($database, $password);

            DatabaseAudit::record($actor, DatabaseAudit::CREATED, $database, [
                'command_id' => $command->id,
                'node_id' => $node->id,
                'region' => $database->region,
                'pg_database' => $database->pg_database,
                ...($lockedProject === null ? [] : ['project_id' => $lockedProject->id]),
            ]);

            if ($lockedProject !== null) {
                $this->attacher->attach($actor, $database, $lockedProject, $data->deployWhenReady ? $actor : null);
            }

            return $database;
        });
    }

    /**
     * The requested name, still free under the lock (the request checked
     * too), or without one the project's name, followed by ` 2`, ` 3`, ...
     * until no live database of the workspace has it, cut to fit.
     */
    private function nameFor(Workspace $workspace, ?string $requested, ?Project $project): string
    {
        /** @var list<string> $taken */
        $taken = ManagedDatabase::query()->where('workspace_id', $workspace->id)->pluck('name')->all();

        if ($requested !== null) {
            if (in_array($requested, $taken, true)) {
                throw ValidationException::withMessages(['name' => [__('validation.unique', ['attribute' => __('validation.attributes.name')])]]);
            }

            return $requested;
        }

        if ($project === null) {
            throw new InvalidArgumentException('A database without a project needs a name.');
        }

        return UniqueDisplayName::from($project->name, $taken, ManagedDatabase::MAX_NAME_LENGTH);
    }

    /**
     * @throws DatabaseLimitExceededException
     * @throws DatabaseStorageLimitExceededException
     */
    private function checkLimits(Workspace $workspace): void
    {
        $limits = $this->limits->limitsFor($workspace);
        $count = ManagedDatabase::countIn($workspace->id);

        if ($count >= $limits->max_databases) {
            throw new DatabaseLimitExceededException($limits->max_databases, $count);
        }

        // Its databases are locked at the limit; a new one would be at once.
        $storage = ManagedDatabase::storageBytesIn($workspace->id);

        if ($storage >= DatabaseSize::bytesOf($limits->max_database_storage_mb)) {
            throw new DatabaseStorageLimitExceededException($limits->max_database_storage_mb, $storage);
        }
    }

    /**
     * A name for the database or its role: `p_` and random lowercase letters
     * and digits, never derived from what people type, since database and role
     * names are visible to other tenants of the instance (ADR-020). The two
     * are different values: the agent refuses a role that owns another
     * database (`database_conflict`), and a name is never reused, for either.
     */
    private function newIdentifier(): string
    {
        do {
            $identifier = ManagedDatabase::IDENTIFIER_PREFIX.Str::lower(Str::random(ManagedDatabase::IDENTIFIER_RANDOM_LENGTH));
        } while (ManagedDatabase::withTrashed()->where('pg_database', $identifier)->orWhere('pg_role', $identifier)->exists());

        return $identifier;
    }
}
