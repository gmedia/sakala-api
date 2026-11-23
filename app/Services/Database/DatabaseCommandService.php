<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DatabaseExtension;
use App\Models\AgentCommand;
use App\Models\ManagedDatabase;
use App\Services\Agent\AgentCommandPayloadMaterializer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Creates the agent commands for one managed database. Every command is
 * pinned to the node that hosts the database and waits for it without a
 * deadline, like a deployment waiting for its node. A password travels
 * encrypted in `agent_commands.payload` and is decrypted only on the wire to
 * that node (AgentCommandPayloadMaterializer).
 *
 * Payloads (additive within protocol revision 4):
 *
 * - CreateDatabase: `database`, `role`, `password`
 * - DropDatabase: `database`, `role`
 * - RotateDatabasePassword: `role`, `password`
 * - LockDatabase, UnlockDatabase: `database`
 * - InstallDatabaseExtension: `database`, `extension`
 *
 * These are exactly the agent's payloads (sakala-agent
 * `examples/commands/database-commands.json`).
 */
final class DatabaseCommandService
{
    /** Payload key of the database name on the node. */
    public const DATABASE_KEY = 'database';

    /** Payload key of the login role on the node. */
    public const ROLE_KEY = 'role';

    /** Payload key of the extension InstallDatabaseExtension installs. */
    public const EXTENSION_KEY = 'extension';

    public function create(ManagedDatabase $database, string $password): AgentCommand
    {
        return $this->dispatch($database, AgentCommandType::CreateDatabase, [
            self::DATABASE_KEY => $database->pg_database,
            self::ROLE_KEY => $database->pg_role,
            AgentCommandPayloadMaterializer::PASSWORD_KEY => Crypt::encryptString($password),
        ]);
    }

    public function drop(ManagedDatabase $database): AgentCommand
    {
        return $this->dispatch($database, AgentCommandType::DropDatabase, [
            self::DATABASE_KEY => $database->pg_database,
            self::ROLE_KEY => $database->pg_role,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $requestContext  What the control plane does once the node reports
     *                                                     (CredentialRotation); never sent to the node.
     */
    public function rotatePassword(ManagedDatabase $database, string $password, ?array $requestContext = null): AgentCommand
    {
        return $this->dispatch($database, AgentCommandType::RotateDatabasePassword, [
            self::ROLE_KEY => $database->pg_role,
            AgentCommandPayloadMaterializer::PASSWORD_KEY => Crypt::encryptString($password),
        ], $requestContext);
    }

    public function lock(ManagedDatabase $database): AgentCommand
    {
        return $this->dispatch($database, AgentCommandType::LockDatabase, [
            self::DATABASE_KEY => $database->pg_database,
        ]);
    }

    public function unlock(ManagedDatabase $database): AgentCommand
    {
        return $this->dispatch($database, AgentCommandType::UnlockDatabase, [
            self::DATABASE_KEY => $database->pg_database,
        ]);
    }

    public function installExtension(ManagedDatabase $database, DatabaseExtension $extension): AgentCommand
    {
        return $this->dispatch($database, AgentCommandType::InstallDatabaseExtension, [
            self::DATABASE_KEY => $database->pg_database,
            self::EXTENSION_KEY => $extension->value,
        ]);
    }

    /** The open install of the extension for the database, if any. */
    public function openExtensionInstall(ManagedDatabase $database, DatabaseExtension $extension): ?AgentCommand
    {
        return $database->commands()
            ->where('type', AgentCommandType::InstallDatabaseExtension)
            ->whereIn('status', AgentCommandStatus::openCases())
            ->where('payload->'.self::EXTENSION_KEY, $extension->value)
            ->first();
    }

    /**
     * The password a CreateDatabase or RotateDatabasePassword carries, or null
     * for another command, or one whose password was already erased.
     */
    public function passwordOf(AgentCommand $command): ?string
    {
        if (! $command->type->carriesDatabasePassword()) {
            return null;
        }

        $encrypted = $command->payload[AgentCommandPayloadMaterializer::PASSWORD_KEY] ?? null;

        return is_string($encrypted) ? Crypt::decryptString($encrypted) : null;
    }

    /**
     * Remove every password copy from the database's commands, once the
     * database is dropped and none of them can run again.
     */
    public function erasePasswords(ManagedDatabase $database): void
    {
        $database->commands()
            ->whereIn('type', [AgentCommandType::CreateDatabase, AgentCommandType::RotateDatabasePassword])
            ->get()
            ->each(static function (AgentCommand $command): void {
                $payload = $command->payload ?? [];
                unset($payload[AgentCommandPayloadMaterializer::PASSWORD_KEY]);
                $command->update(['payload' => $payload]);
            });
    }

    /**
     * @param  array<string, string>  $payload
     * @param  array<string, mixed>|null  $requestContext
     */
    private function dispatch(ManagedDatabase $database, AgentCommandType $type, array $payload, ?array $requestContext = null): AgentCommand
    {
        return AgentCommand::query()->create([
            'database_id' => $database->id,
            'agent_node_id' => $database->node_id,
            'type' => $type,
            'status' => AgentCommandStatus::Pending,
            'payload' => $payload,
            'request_context' => $requestContext,
            'idempotency_key' => (string) Str::uuid(),
            'available_at' => now(),
            // Waits for the database's node however long it is offline: the
            // database cannot move to another node.
            'expires_at' => null,
        ]);
    }
}
