<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Enums\AgentCommandType;
use App\Exceptions\Database\DatabaseBusyException;
use App\Exceptions\Database\DatabaseNotReadyException;
use App\Models\ManagedDatabase;
use App\Models\User;
use App\Services\Database\DatabaseCommandService;
use App\Support\Database\DatabaseAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Asks the database node for a new password. The stored one stays until the
 * node reports success, so deployments made meanwhile still connect; then the
 * new one is stored and every attached project shows `redeploy_required`.
 * Allowed while locked, because a leaked password must be replaceable.
 */
final class RotateDatabasePasswordAction
{
    public function __construct(
        private readonly DatabaseCommandService $commands,
    ) {}

    /**
     * @throws DatabaseNotReadyException
     * @throws DatabaseBusyException
     */
    public function handle(User $actor, ManagedDatabase $database): ManagedDatabase
    {
        return DB::transaction(function () use ($actor, $database): ManagedDatabase {
            /** @var ManagedDatabase $locked */
            $locked = ManagedDatabase::query()->whereKey($database->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isProvisioned()) {
                throw new DatabaseNotReadyException($locked->status);
            }

            if ($locked->openCommand(AgentCommandType::RotateDatabasePassword) !== null) {
                throw DatabaseBusyException::rotationInProgress();
            }

            $command = $this->commands->rotatePassword($locked, Str::random(ManagedDatabase::PASSWORD_LENGTH));

            DatabaseAudit::record($actor, DatabaseAudit::PASSWORD_ROTATION_REQUESTED, $locked, ['command_id' => $command->id]);

            return $locked;
        });
    }
}
