<?php

declare(strict_types=1);

namespace App\Actions\Valkey;

use App\Enums\AgentCommandType;
use App\Enums\ValkeyLockSource;
use App\Enums\ValkeyStatus;
use App\Models\ManagedValkey;
use App\Models\Project;
use App\Services\Valkey\ValkeyCommandOutcomeService;
use Illuminate\Support\Facades\DB;

/**
 * Follows a project's suspension with its Valkey store (ADR-023: "for abuse
 * or suspension an admin disables the `default` user"). A store may serve
 * several projects of one workspace, so it is locked only once every
 * attached project is suspended, and unlocked when one of them is
 * unsuspended, but only if the suspension locked it: an admin lock stays
 * until `valkeys:unlock`.
 *
 * Runs after the suspension's own transaction commits, in a transaction of
 * its own that locks the store row first: suspending holds the project row,
 * and every Valkey path locks the store before the project, so doing this
 * inside would invert that order. A store whose lock or unlock is already on
 * its way is left alone; a suspension lock that lands after an unsuspend
 * lifts itself (ValkeyCommandOutcomeService::completeLock()).
 */
final class ValkeySuspensionLock
{
    public function __construct(
        private readonly RequestValkeyLockAction $locks,
        private readonly ValkeyCommandOutcomeService $outcomes,
    ) {}

    /** After a project was suspended. */
    public function suspended(Project $project): void
    {
        $this->each($project, function (ManagedValkey $valkey): void {
            if ($valkey->status === ValkeyStatus::Ready && $this->idle($valkey) && $this->outcomes->everyAttachedProjectSuspended($valkey)) {
                $this->locks->requestWithinTransaction($valkey, lock: true, reason: null, source: ValkeyLockSource::Suspension);
            }
        });
    }

    /** After a project was unsuspended. */
    public function unsuspended(Project $project): void
    {
        $this->each($project, function (ManagedValkey $valkey): void {
            if ($valkey->status === ValkeyStatus::Locked && $valkey->lock_source === ValkeyLockSource::Suspension && $this->idle($valkey)) {
                $this->locks->requestWithinTransaction($valkey, lock: false, reason: null, source: ValkeyLockSource::Suspension);
            }
        });
    }

    /** @param  callable(ManagedValkey): void  $callback */
    private function each(Project $project, callable $callback): void
    {
        $ids = $project->valkeys()->pluck('valkeys.id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $callback): void {
                $valkey = ManagedValkey::query()->whereKey($id)->lockForUpdate()->first();

                if ($valkey !== null) {
                    $callback($valkey);
                }
            });
        }
    }

    private function idle(ManagedValkey $valkey): bool
    {
        return $valkey->openCommand(AgentCommandType::LockValkey) === null
            && $valkey->openCommand(AgentCommandType::UnlockValkey) === null;
    }
}
