<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Enums\DatabaseExtension;
use App\Exceptions\Database\DatabaseNotReadyException;
use App\Models\ManagedDatabase;
use App\Models\User;
use App\Services\Database\DatabaseCommandService;
use App\Support\Database\DatabaseAudit;
use Illuminate\Support\Facades\DB;

/**
 * Asks the database node to install an extension from the allow-list into the
 * database's `public` schema, as superuser, since `vector` is not trusted by
 * PostgreSQL. Installing one that is already there succeeds; asking again
 * while the same install is on its way changes nothing. A locked database can
 * still get one: the superuser connects regardless of the lock.
 */
final class InstallDatabaseExtensionAction
{
    public function __construct(
        private readonly DatabaseCommandService $commands,
    ) {}

    /**
     * @throws DatabaseNotReadyException
     */
    public function handle(User $actor, ManagedDatabase $database, DatabaseExtension $extension): ManagedDatabase
    {
        return DB::transaction(function () use ($actor, $database, $extension): ManagedDatabase {
            /** @var ManagedDatabase $locked */
            $locked = ManagedDatabase::query()->whereKey($database->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isProvisioned()) {
                throw new DatabaseNotReadyException($locked->status);
            }

            if ($this->commands->openExtensionInstall($locked, $extension) !== null) {
                return $locked;
            }

            $command = $this->commands->installExtension($locked, $extension);

            DatabaseAudit::record($actor, DatabaseAudit::EXTENSION_INSTALL_REQUESTED, $locked, [
                'command_id' => $command->id,
                'extension' => $extension->value,
            ]);

            return $locked;
        });
    }
}
