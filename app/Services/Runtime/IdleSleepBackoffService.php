<?php

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Models\AgentCommand;
use Carbon\CarbonImmutable;

/**
 * How long the idle sweep waits after idle sleeps of a deployment failed. The
 * failures in a row since a lifecycle command last succeeded on that
 * deployment count (see AgentCommand::scopeFailedSinceLastLifecycleSuccess()):
 * after the first the sweep waits one idle window, and the wait doubles with
 * every further failure up to a day. A missing container is not retried at all
 * (Project::scopeIdleSleepBlocked()).
 */
final class IdleSleepBackoffService
{
    /** The longest wait between two idle sleeps of one deployment: a day. */
    public const MAX_DELAY_MINUTES = 24 * 60;

    /** Each further failure multiplies the wait by this. */
    public const DELAY_GROWTH_FACTOR = 2;

    /**
     * Minutes to wait after the last of `$failures` failed idle sleeps in a
     * row, for a workspace whose idle window is `$windowMinutes`.
     */
    public function delayMinutes(int $windowMinutes, int $failures): int
    {
        if ($failures < 1) {
            return 0;
        }

        $delay = max(1, $windowMinutes);

        for ($failure = 1; $failure < $failures && $delay < self::MAX_DELAY_MINUTES; $failure++) {
            $delay *= self::DELAY_GROWTH_FACTOR;
        }

        return min($delay, self::MAX_DELAY_MINUTES);
    }

    /**
     * The deployments among `$deploymentIds` whose last failed idle sleep is
     * more recent than its delay allows, in one query.
     *
     * @param  list<string>  $deploymentIds
     * @return list<string>
     */
    public function waitingDeploymentIds(array $deploymentIds, int $windowMinutes, CarbonImmutable $now): array
    {
        if ($deploymentIds === []) {
            return [];
        }

        /** @var list<object{deployment_id: string, failures: int|string, last_failed_at: string}> $rows */
        $rows = AgentCommand::query()
            ->idleSleep()
            ->failedSinceLastLifecycleSuccess()
            ->whereIn('deployment_id', $deploymentIds)
            ->groupBy('deployment_id')
            ->toBase()
            ->selectRaw('deployment_id, count(*) as failures, max(failed_at) as last_failed_at')
            ->get()
            ->all();

        $waiting = [];

        foreach ($rows as $row) {
            $retryAt = CarbonImmutable::parse($row->last_failed_at)
                ->addMinutes($this->delayMinutes($windowMinutes, (int) $row->failures));

            if ($now->lt($retryAt)) {
                $waiting[] = $row->deployment_id;
            }
        }

        return $waiting;
    }
}
