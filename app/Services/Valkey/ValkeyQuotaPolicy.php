<?php

declare(strict_types=1);

namespace App\Services\Valkey;

use App\Exceptions\Valkey\ValkeyLimitExceededException;
use App\Exceptions\Valkey\ValkeyMemoryLimitExceededException;
use App\Models\ManagedValkey;
use App\Models\Workspace;
use App\Services\Runtime\PilotRuntimeLimitService;

/**
 * The workspace side of Valkey quotas (ADR-023): how many stores a workspace
 * may hold and how much `maxmemory` they may reserve together. Callers hold
 * the workspace row lock, so the count and the sum cannot move between the
 * check and their write. Lowering a profile never shrinks a store; it only
 * refuses new stores and increases.
 */
final class ValkeyQuotaPolicy
{
    public function __construct(
        private readonly PilotRuntimeLimitService $limits,
    ) {}

    /**
     * @throws ValkeyLimitExceededException
     * @throws ValkeyMemoryLimitExceededException
     */
    public function ensureCreateAllowed(Workspace $lockedWorkspace, int $sizeMb): void
    {
        $limits = $this->limits->limitsFor($lockedWorkspace);
        $count = ManagedValkey::countIn($lockedWorkspace->id);

        if ($count >= $limits->max_valkeys) {
            throw new ValkeyLimitExceededException($limits->max_valkeys, $count);
        }

        $this->ensureFits($limits->max_valkey_memory_mb, ManagedValkey::reservedMbIn($lockedWorkspace->id), $sizeMb);
    }

    /**
     * Refuses growing the store to `$sizeMb` when the workspace's other
     * reservations plus the new size would pass its memory limit. A decrease
     * is always allowed.
     *
     * @throws ValkeyMemoryLimitExceededException
     */
    public function ensureResizeAllowed(Workspace $lockedWorkspace, ManagedValkey $valkey, int $sizeMb): void
    {
        if ($sizeMb <= $valkey->maxmemory_mb) {
            return;
        }

        $this->ensureFits(
            $this->limits->limitsFor($lockedWorkspace)->max_valkey_memory_mb,
            ManagedValkey::reservedMbIn($lockedWorkspace->id, except: $valkey->id),
            $sizeMb,
        );
    }

    /** @throws ValkeyMemoryLimitExceededException */
    private function ensureFits(int $limitMb, int $reservedMb, int $requestedMb): void
    {
        if ($reservedMb + $requestedMb > $limitMb) {
            throw new ValkeyMemoryLimitExceededException($limitMb, $reservedMb, $requestedMb);
        }
    }
}
