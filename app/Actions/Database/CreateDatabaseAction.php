<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Enums\DatabaseEngine;
use App\Enums\DatabaseStatus;
use App\Exceptions\Database\DatabaseLimitExceededException;
use App\Exceptions\Database\DatabaseRegionUnavailableException;
use App\Exceptions\Database\DatabaseStorageLimitExceededException;
use App\Models\ManagedDatabase;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Agent\AgentNodeSchedulerService;
use App\Services\Database\DatabaseCommandService;
use App\Services\Runtime\PilotRuntimeLimitService;
use App\Support\Database\DatabaseAudit;
use App\Support\Database\DatabaseSize;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a managed database in a workspace: random names and password, a
 * database node of the region, and the CreateDatabase command for it. The
 * database is `provisioning` until the node reports. Quotas are checked under
 * the workspace row lock, so two requests cannot both take the last slot;
 * admins acting are not limited, as with projects.
 */
final class CreateDatabaseAction
{
    public function __construct(
        private readonly PilotRuntimeLimitService $limits,
        private readonly AgentNodeSchedulerService $scheduler,
        private readonly DatabaseCommandService $commands,
    ) {}

    /**
     * @throws DatabaseLimitExceededException
     * @throws DatabaseStorageLimitExceededException
     * @throws DatabaseRegionUnavailableException
     */
    public function handle(User $actor, Workspace $workspace, string $name, ?string $region): ManagedDatabase
    {
        return DB::transaction(function () use ($actor, $workspace, $name, $region): ManagedDatabase {
            /** @var Workspace $locked */
            $locked = Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();

            if (! $actor->isAdmin()) {
                $this->checkLimits($locked);
            }

            // The request checked too; this one holds under the lock.
            if (ManagedDatabase::query()->where('workspace_id', $locked->id)->where('name', $name)->exists()) {
                throw ValidationException::withMessages(['name' => [__('validation.unique', ['attribute' => __('validation.attributes.name')])]]);
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
            ]);

            return $database;
        });
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
