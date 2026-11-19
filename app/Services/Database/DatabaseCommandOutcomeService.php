<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\AgentCommandType;
use App\Enums\DatabaseStatus;
use App\Jobs\Database\RedeployAfterPasswordRotationJob;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Support\Database\DatabaseAudit;
use App\Support\Database\DatabasePasswordRotation;

/**
 * What a database command's result means for its database. Called inside the
 * transaction that closes the command, after the command row is locked; the
 * database row is locked next, then any attached project, the same order the
 * database actions take. A result never moves a database out of a status it
 * left meanwhile: a late lock does not lock a database that is being dropped.
 */
final class DatabaseCommandOutcomeService
{
    public function __construct(
        private readonly DatabaseCommandService $commands,
    ) {}

    public function completed(AgentNode $agent, AgentCommand $command): void
    {
        $database = $this->lockDatabase($command);

        if ($database === null) {
            return;
        }

        $metadata = ['command_id' => $command->id];

        match ($command->type) {
            AgentCommandType::CreateDatabase => $this->transition($database, DatabaseStatus::Provisioning, DatabaseStatus::Ready)
                && $this->audit($agent, DatabaseAudit::PROVISIONED, $database, $metadata),
            AgentCommandType::DropDatabase => $this->completeDrop($agent, $database, $metadata),
            AgentCommandType::RotateDatabasePassword => $this->completeRotation($agent, $command, $database, $metadata),
            AgentCommandType::LockDatabase => $this->transition($database, DatabaseStatus::Ready, DatabaseStatus::Locked)
                && $this->audit($agent, DatabaseAudit::LOCKED, $database, $metadata),
            AgentCommandType::UnlockDatabase => $this->transition($database, DatabaseStatus::Locked, DatabaseStatus::Ready)
                && $this->audit($agent, DatabaseAudit::UNLOCKED, $database, $metadata),
            AgentCommandType::InstallDatabaseExtension => $this->audit($agent, DatabaseAudit::EXTENSION_INSTALLED, $database, [
                ...$metadata,
                'extension' => $command->payload[DatabaseCommandService::EXTENSION_KEY] ?? null,
            ]),
            default => null,
        };
    }

    /**
     * A failed or expired command. `$actor` is the node that reported it, or
     * the expiry sweep. A failed drop, lock, or unlock changes no status: the
     * owner can delete again, and the storage sweep asks again.
     */
    public function failed(AgentNode|string $actor, AgentCommand $command, string $errorCode): void
    {
        $database = $this->lockDatabase($command);

        if ($database === null) {
            return;
        }

        $metadata = ['command_id' => $command->id, 'error_code' => $errorCode];

        match ($command->type) {
            AgentCommandType::CreateDatabase => $this->transition($database, DatabaseStatus::Provisioning, DatabaseStatus::Failed)
                && $this->audit($actor, DatabaseAudit::PROVISIONING_FAILED, $database, $metadata),
            AgentCommandType::DropDatabase => $this->audit($actor, DatabaseAudit::DROP_FAILED, $database, $metadata),
            AgentCommandType::RotateDatabasePassword => $this->audit($actor, DatabaseAudit::PASSWORD_ROTATION_FAILED, $database, $metadata),
            AgentCommandType::LockDatabase => $this->audit($actor, DatabaseAudit::LOCK_FAILED, $database, $metadata),
            AgentCommandType::UnlockDatabase => $this->audit($actor, DatabaseAudit::UNLOCK_FAILED, $database, $metadata),
            AgentCommandType::InstallDatabaseExtension => $this->audit($actor, DatabaseAudit::EXTENSION_INSTALL_FAILED, $database, [
                ...$metadata,
                'extension' => $command->payload[DatabaseCommandService::EXTENSION_KEY] ?? null,
            ]),
            default => null,
        };
    }

    /** @param  array<string, mixed>  $metadata */
    private function completeDrop(AgentNode $agent, ManagedDatabase $database, array $metadata): bool
    {
        if ($database->trashed()) {
            return false;
        }

        $database->forceFill([
            'status' => DatabaseStatus::Deleted,
            'password' => null,
            'deleted_at' => now(),
        ])->save();

        $this->commands->erasePasswords($database);

        return $this->audit($agent, DatabaseAudit::DELETED, $database, $metadata);
    }

    /**
     * The node now accepts the new password only: store it, and mark every
     * attached project for a redeploy, since its running containers still
     * have the old one. When the rotation asked for it, the redeploys are
     * queued once this transaction commits.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function completeRotation(AgentNode $agent, AgentCommand $command, ManagedDatabase $database, array $metadata): bool
    {
        $password = $this->commands->passwordOf($command);

        if ($password === null || ! $database->status->isProvisioned()) {
            return false;
        }

        $database->update(['password' => $password]);

        $projects = Project::query()
            ->whereIn('id', $database->attachments()->select('project_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($projects as $project) {
            $project->refreshDatabaseFingerprint();
            $project->save();
        }

        if (DatabasePasswordRotation::redeploysAttached($command)) {
            RedeployAfterPasswordRotationJob::dispatch($command->id)->afterCommit();
        }

        return $this->audit($agent, DatabaseAudit::PASSWORD_ROTATED, $database, [
            ...$metadata,
            'project_ids' => $projects->modelKeys(),
        ]);
    }

    private function transition(ManagedDatabase $database, DatabaseStatus $from, DatabaseStatus $to): bool
    {
        if ($database->status !== $from) {
            return false;
        }

        $database->update(['status' => $to]);

        return true;
    }

    /** @param  array<string, mixed>  $metadata */
    private function audit(AgentNode|string $actor, string $action, ManagedDatabase $database, array $metadata): bool
    {
        DatabaseAudit::record($actor, $action, $database, $metadata);

        return true;
    }

    private function lockDatabase(AgentCommand $command): ?ManagedDatabase
    {
        if ($command->database_id === null) {
            return null;
        }

        return ManagedDatabase::withTrashed()
            ->whereKey($command->database_id)
            ->lockForUpdate()
            ->first();
    }
}
