<?php

declare(strict_types=1);

namespace App\Actions\Valkey;

use App\Enums\AgentCommandType;
use App\Enums\ValkeyLockSource;
use App\Enums\ValkeyStatus;
use App\Models\AgentCommand;
use App\Models\ManagedValkey;
use App\Services\Valkey\ValkeyCommandService;
use App\Support\Valkey\ValkeyAudit;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Locks a Valkey store, or unlocks it again (ADR-023): for abuse an admin
 * runs `valkeys:lock` and `valkeys:unlock`; suspending every project that
 * uses a store locks it too (ValkeySuspensionLock). Locking disables the
 * store's `default` user and closes its connections, so applications cannot
 * log in; the data stays and backups keep running. The status turns
 * `locked` once the node reports. Lock needs a ready store and unlock a
 * locked one, and neither runs while the other is open.
 */
final class RequestValkeyLockAction
{
    public function __construct(
        private readonly ValkeyCommandService $commands,
    ) {}

    /** @throws DomainException when the store is not in the status the request needs, or a lock or unlock is open. */
    public function handle(ManagedValkey $valkey, bool $lock, ?string $reason, ValkeyLockSource $source = ValkeyLockSource::Admin): AgentCommand
    {
        return DB::transaction(function () use ($valkey, $lock, $reason, $source): AgentCommand {
            /** @var ManagedValkey $locked */
            $locked = ManagedValkey::query()->whereKey($valkey->id)->lockForUpdate()->firstOrFail();

            return $this->requestWithinTransaction($locked, $lock, $reason, $source);
        });
    }

    /**
     * The same, for a caller that already holds the store row lock.
     *
     * @throws DomainException
     */
    public function requestWithinTransaction(ManagedValkey $locked, bool $lock, ?string $reason, ValkeyLockSource $source = ValkeyLockSource::Admin): AgentCommand
    {
        $required = $lock ? ValkeyStatus::Ready : ValkeyStatus::Locked;

        if ($locked->status !== $required) {
            throw new DomainException(sprintf('Valkey store %s is %s; %s needs %s.', $locked->id, $locked->status->value, $lock ? 'lock' : 'unlock', $required->value));
        }

        if ($locked->openCommand(AgentCommandType::LockValkey) !== null || $locked->openCommand(AgentCommandType::UnlockValkey) !== null) {
            throw new DomainException(sprintf('Valkey store %s already has a lock or unlock on its way.', $locked->id));
        }

        $command = $lock ? $this->commands->lock($locked, $source) : $this->commands->unlock($locked);

        ValkeyAudit::record(
            match (true) {
                $source === ValkeyLockSource::Suspension => ValkeyAudit::SUSPENSION_ACTOR,
                $lock => ValkeyAudit::LOCK_ACTOR,
                default => ValkeyAudit::UNLOCK_ACTOR,
            },
            $lock ? ValkeyAudit::LOCK_REQUESTED : ValkeyAudit::UNLOCK_REQUESTED,
            $locked,
            array_filter([
                'command_id' => $command->id,
                'lock_source' => $source->value,
                'reason' => $reason,
            ], static fn (mixed $value): bool => $value !== null),
        );

        return $command;
    }
}
