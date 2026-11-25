<?php

declare(strict_types=1);

namespace App\Actions\Valkey;

use App\Enums\AgentCommandType;
use App\Exceptions\Valkey\ValkeyBusyException;
use App\Exceptions\Valkey\ValkeyCapacityUnavailableException;
use App\Exceptions\Valkey\ValkeyMemoryLimitExceededException;
use App\Exceptions\Valkey\ValkeyNotReadyException;
use App\Models\AgentNode;
use App\Models\ManagedValkey;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Agent\AgentNodeSchedulerService;
use App\Services\Valkey\ValkeyCommandService;
use App\Services\Valkey\ValkeyQuotaPolicy;
use App\Support\Valkey\ValkeyAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renames a Valkey store and resizes it. A rename never touches the node. A
 * resize needs a ready or locked store and neither an open ResizeValkey nor
 * an open CreateValkey; growth must fit the workspace's memory limit (admins
 * excepted) and the store's own node budget, both without the store's own
 * reservation. The reservation becomes the larger of the old and the new
 * size until the node reports, so shrinking frees nothing early. A
 * persistent store refuses to shrink below its data on the node
 * (`valkey_size_below_usage`); a cache store evicts keys instead. The mode
 * never changes in v1.
 *
 * Locks: the workspace row first, then the store's node (for a resize, FOR
 * NO KEY UPDATE), then the store row.
 */
final class UpdateValkeyAction
{
    public function __construct(
        private readonly AgentNodeSchedulerService $scheduler,
        private readonly ValkeyCommandService $commands,
        private readonly ValkeyQuotaPolicy $quotas,
    ) {}

    /**
     * @return array{valkey: ManagedValkey, resize_requested: bool}
     *
     * @throws ValkeyNotReadyException
     * @throws ValkeyBusyException
     * @throws ValkeyMemoryLimitExceededException
     * @throws ValkeyCapacityUnavailableException
     */
    public function handle(User $actor, ManagedValkey $valkey, ?string $name, ?int $maxmemoryMb): array
    {
        return DB::transaction(function () use ($actor, $valkey, $name, $maxmemoryMb): array {
            /** @var Workspace|null $workspace */
            $workspace = $valkey->workspace_id === null ? null : Workspace::query()->whereKey($valkey->workspace_id)->lockForUpdate()->first();
            // A store never moves, so its node is known before the store row is locked.
            $node = $maxmemoryMb === null ? null : $this->scheduler->lockValkeyNode($valkey->node_id);
            /** @var ManagedValkey $locked */
            $locked = ManagedValkey::query()->whereKey($valkey->id)->lockForUpdate()->firstOrFail();

            if ($name !== null && $name !== $locked->name) {
                $this->rename($actor, $locked, $name);
            }

            $requested = $maxmemoryMb !== null && $maxmemoryMb !== $locked->maxmemory_mb
                && $node !== null
                && $this->resize($actor, $workspace, $node, $locked, $maxmemoryMb);

            return ['valkey' => $locked, 'resize_requested' => $requested];
        });
    }

    private function rename(User $actor, ManagedValkey $valkey, string $name): void
    {
        if (ManagedValkey::query()->where('workspace_id', $valkey->workspace_id)->where('name', $name)->whereKeyNot($valkey->id)->exists()) {
            throw ValidationException::withMessages(['name' => [__('validation.unique', ['attribute' => __('validation.attributes.name')])]]);
        }

        $from = $valkey->name;
        $valkey->update(['name' => $name]);

        ValkeyAudit::record($actor, ValkeyAudit::RENAMED, $valkey, ['from' => $from, 'to' => $name]);
    }

    /**
     * @throws ValkeyNotReadyException
     * @throws ValkeyBusyException
     * @throws ValkeyMemoryLimitExceededException
     * @throws ValkeyCapacityUnavailableException
     */
    private function resize(User $actor, ?Workspace $workspace, AgentNode $lockedNode, ManagedValkey $valkey, int $maxmemoryMb): bool
    {
        if (! $valkey->status->isProvisioned()) {
            throw new ValkeyNotReadyException($valkey->status);
        }

        if ($valkey->openCommand(AgentCommandType::ResizeValkey) !== null) {
            throw ValkeyBusyException::resizeInProgress();
        }

        if ($valkey->openCommand(AgentCommandType::CreateValkey) !== null) {
            throw ValkeyBusyException::creationInProgress();
        }

        if ($maxmemoryMb > $valkey->maxmemory_mb) {
            if ($workspace !== null && ! $actor->isAdmin()) {
                $this->quotas->ensureResizeAllowed($workspace, $valkey, $maxmemoryMb);
            }

            $this->scheduler->ensureValkeyNodeFits($lockedNode, $maxmemoryMb, $valkey->id);
        }

        $from = $valkey->maxmemory_mb;
        $valkey->update(['reserved_maxmemory_mb' => max($valkey->maxmemory_mb, $maxmemoryMb)]);
        $command = $this->commands->resize($valkey, $maxmemoryMb);

        ValkeyAudit::record($actor, ValkeyAudit::RESIZE_REQUESTED, $valkey, [
            'command_id' => $command->id,
            'from_mb' => $from,
            'to_mb' => $maxmemoryMb,
        ]);

        return true;
    }
}
