<?php

declare(strict_types=1);

namespace App\Actions\Valkey;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\ValkeyStatus;
use App\Exceptions\Valkey\ValkeyBusyException;
use App\Exceptions\Valkey\ValkeyInUseException;
use App\Models\AgentCommand;
use App\Models\ManagedValkey;
use App\Models\User;
use App\Services\Valkey\ValkeyCommandService;
use App\Support\Valkey\ValkeyAudit;
use Illuminate\Support\Facades\DB;

/**
 * Deleting removes the store's container, network, and data on its node:
 * the status becomes `deleting`, and the row is soft deleted once the node
 * reports. Only a store no project uses can be deleted; the node also
 * refuses while a container of a detached project still runs on the store's
 * network (`valkey_in_use`), and asking again after that project's redeploy
 * sends another DeleteValkey. A CreateValkey no node has claimed yet is
 * cancelled instead, since nothing exists on the node. A failed store gets a
 * DeleteValkey too, because its creation may have half succeeded and the
 * node keeps its record and address until then.
 */
final class DeleteValkeyAction
{
    /** `error_code` of a CreateValkey cancelled because its store was deleted first. */
    public const CANCELLED_CREATE_CODE = 'valkey_deleted';

    public function __construct(
        private readonly ValkeyCommandService $commands,
    ) {}

    /**
     * @return array{valkey: ManagedValkey, removed: bool} `removed` when the row went at once (nothing was on the node).
     *
     * @throws ValkeyInUseException
     * @throws ValkeyBusyException
     */
    public function handle(User $actor, ManagedValkey $valkey, ?string $reason = null): array
    {
        return DB::transaction(function () use ($actor, $valkey, $reason): array {
            /** @var ManagedValkey $locked */
            $locked = ManagedValkey::query()->whereKey($valkey->id)->lockForUpdate()->firstOrFail();

            $projects = $locked->projects()->orderBy('name')->get();

            if ($projects->isNotEmpty()) {
                throw new ValkeyInUseException($projects);
            }

            if ($locked->status === ValkeyStatus::Deleting && $locked->openCommand(AgentCommandType::DeleteValkey) !== null) {
                return ['valkey' => $locked, 'removed' => false];
            }

            if ($locked->status === ValkeyStatus::Provisioning) {
                return ['valkey' => $this->cancelProvisioning($actor, $locked, $reason), 'removed' => true];
            }

            $locked->update(['status' => ValkeyStatus::Deleting]);
            $command = $this->commands->delete($locked);

            ValkeyAudit::record($actor, ValkeyAudit::DELETE_REQUESTED, $locked, array_filter([
                'command_id' => $command->id,
                'reason' => $reason,
            ], static fn (mixed $value): bool => $value !== null));

            return ['valkey' => $locked, 'removed' => false];
        });
    }

    /**
     * Nothing exists on the node while no node has claimed the CreateValkey,
     * so it is cancelled and the row goes at once. A node already creating it
     * must finish first.
     *
     * @throws ValkeyBusyException
     */
    private function cancelProvisioning(User $actor, ManagedValkey $valkey, ?string $reason): ManagedValkey
    {
        $create = $valkey->openCommand(AgentCommandType::CreateValkey);

        // Conditional, like claim: whichever writes first wins the command.
        $cancelled = $create === null || AgentCommand::query()
            ->whereKey($create->id)
            ->where('status', AgentCommandStatus::Pending)
            ->update([
                'status' => AgentCommandStatus::Cancelled,
                'error_code' => self::CANCELLED_CREATE_CODE,
                'error_message' => 'The Valkey store was deleted before a node claimed this command.',
                'updated_at' => now(),
            ]) === 1;

        if (! $cancelled) {
            throw ValkeyBusyException::creationInProgress();
        }

        $valkey->forceFill([
            'status' => ValkeyStatus::Deleted,
            'password' => null,
            'deleted_at' => now(),
        ])->save();

        $this->commands->eraseSecrets($valkey);

        ValkeyAudit::record($actor, ValkeyAudit::DELETED, $valkey, array_filter([
            'cancelled_command_id' => $create?->id,
            'reason' => $reason,
        ], static fn (mixed $value): bool => $value !== null));

        return $valkey;
    }
}
