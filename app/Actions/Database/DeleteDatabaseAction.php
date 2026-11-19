<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DatabaseStatus;
use App\Exceptions\Database\DatabaseBusyException;
use App\Exceptions\Database\DatabaseInUseException;
use App\Models\AgentCommand;
use App\Models\ManagedDatabase;
use App\Models\User;
use App\Services\Database\DatabaseCommandService;
use App\Support\Database\DatabaseAudit;
use Illuminate\Support\Facades\DB;

/**
 * Deleting drops the database on its node: the status becomes `deleting`, and
 * the row is soft deleted once the node reports the drop. Only a database no
 * project uses can be deleted, so no running application loses its data by
 * accident. A CreateDatabase no node has claimed yet is cancelled instead,
 * since nothing exists on the node. Asking again while a drop is on its way
 * changes nothing; asking after a failed drop sends another one.
 */
final class DeleteDatabaseAction
{
    /** `error_code` of a CreateDatabase cancelled because its database was deleted first. */
    public const CANCELLED_CREATE_CODE = 'database_deleted';

    public function __construct(
        private readonly DatabaseCommandService $commands,
    ) {}

    /**
     * @throws DatabaseInUseException
     * @throws DatabaseBusyException
     */
    public function handle(User $actor, ManagedDatabase $database, ?string $reason = null): ManagedDatabase
    {
        return DB::transaction(function () use ($actor, $database, $reason): ManagedDatabase {
            /** @var ManagedDatabase $locked */
            $locked = ManagedDatabase::query()->whereKey($database->id)->lockForUpdate()->firstOrFail();

            $projects = $locked->projects()->orderBy('name')->get();

            if ($projects->isNotEmpty()) {
                throw new DatabaseInUseException($projects);
            }

            if ($locked->status === DatabaseStatus::Deleting && $locked->openCommand(AgentCommandType::DropDatabase) !== null) {
                return $locked;
            }

            if ($locked->status === DatabaseStatus::Provisioning) {
                return $this->cancelProvisioning($actor, $locked, $reason);
            }

            $locked->update(['status' => DatabaseStatus::Deleting]);
            $command = $this->commands->drop($locked);

            DatabaseAudit::record($actor, DatabaseAudit::DELETE_REQUESTED, $locked, array_filter([
                'command_id' => $command->id,
                'reason' => $reason,
            ], static fn (mixed $value): bool => $value !== null));

            return $locked;
        });
    }

    /**
     * Nothing exists on the node while no node has claimed the CreateDatabase,
     * so it is cancelled and the row goes at once. A node already creating it
     * must finish first.
     *
     * @throws DatabaseBusyException
     */
    private function cancelProvisioning(User $actor, ManagedDatabase $database, ?string $reason): ManagedDatabase
    {
        $create = $database->openCommand(AgentCommandType::CreateDatabase);

        // Conditional, like claim: whichever writes first wins the command.
        $cancelled = $create === null || AgentCommand::query()
            ->whereKey($create->id)
            ->where('status', AgentCommandStatus::Pending)
            ->update([
                'status' => AgentCommandStatus::Cancelled,
                'error_code' => self::CANCELLED_CREATE_CODE,
                'error_message' => 'The database was deleted before a node claimed this command.',
                'updated_at' => now(),
            ]) === 1;

        if (! $cancelled) {
            throw DatabaseBusyException::creationInProgress();
        }

        $database->forceFill([
            'status' => DatabaseStatus::Deleted,
            'password' => null,
            'deleted_at' => now(),
        ])->save();

        $this->commands->erasePasswords($database);

        DatabaseAudit::record($actor, DatabaseAudit::DELETED, $database, array_filter([
            'cancelled_command_id' => $create?->id,
            'reason' => $reason,
        ], static fn (mixed $value): bool => $value !== null));

        return $database;
    }
}
