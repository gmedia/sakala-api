<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Data\Deployment\PruneDeploymentLogsResultData;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\DeploymentEvent;
use App\Models\DeploymentLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class PruneDeploymentLogsAction
{
    /** @var list<DeploymentStatus> */
    public const TERMINAL_STATUSES = [
        DeploymentStatus::Succeeded,
        DeploymentStatus::Failed,
        DeploymentStatus::Cancelled,
    ];

    /**
     * Prune deployment logs and events belonging to terminal deployments older than the retention threshold.
     */
    public function handle(
        ?int $days = null,
        bool $dryRun = false,
        int $batchSize = 1000,
    ): PruneDeploymentLogsResultData {
        $effectiveDays = $days ?? (int) config('sakala.pilot_limits.log_retention_days', 7);

        if ($effectiveDays < 1) {
            throw new InvalidArgumentException('Retention days must be at least 1.');
        }

        if ($batchSize < 1) {
            throw new InvalidArgumentException('Batch size must be at least 1.');
        }

        $cutoffDate = CarbonImmutable::now()->subDays($effectiveDays);

        $eligibleDeployments = $this->eligibleDeployments($cutoffDate);
        $terminalDeploymentIds = (clone $eligibleDeployments)->select('id');

        $affectedDeploymentsCount = $eligibleDeployments->count();

        if ($dryRun) {
            $candidateLogsCount = DeploymentLog::query()
                ->whereIn('deployment_id', $terminalDeploymentIds)
                ->count();

            $candidateEventsCount = DeploymentEvent::query()
                ->whereIn('deployment_id', $terminalDeploymentIds)
                ->count();

            return new PruneDeploymentLogsResultData(
                prunedLogsCount: $candidateLogsCount,
                prunedEventsCount: $candidateEventsCount,
                affectedDeploymentsCount: $affectedDeploymentsCount,
                isDryRun: true,
                retentionDays: $effectiveDays,
                cutoffDate: $cutoffDate,
            );
        }

        $prunedLogsCount = 0;
        do {
            /** @var list<int> $logIds */
            $logIds = DeploymentLog::query()
                ->whereIn('deployment_id', $terminalDeploymentIds)
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id')
                ->all();

            if ($logIds === []) {
                break;
            }

            $deleted = DeploymentLog::query()
                ->whereIn('id', $logIds)
                ->delete();

            $prunedLogsCount += $deleted;
        } while ($deleted > 0);

        $prunedEventsCount = 0;
        do {
            /** @var list<int> $eventIds */
            $eventIds = DeploymentEvent::query()
                ->whereIn('deployment_id', $terminalDeploymentIds)
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id')
                ->all();

            if ($eventIds === []) {
                break;
            }

            $deleted = DeploymentEvent::query()
                ->whereIn('id', $eventIds)
                ->delete();

            $prunedEventsCount += $deleted;
        } while ($deleted > 0);

        return new PruneDeploymentLogsResultData(
            prunedLogsCount: $prunedLogsCount,
            prunedEventsCount: $prunedEventsCount,
            affectedDeploymentsCount: $affectedDeploymentsCount,
            isDryRun: false,
            retentionDays: $effectiveDays,
            cutoffDate: $cutoffDate,
        );
    }

    /**
     * @return Builder<Deployment>
     */
    private function eligibleDeployments(CarbonImmutable $cutoffDate): Builder
    {
        return Deployment::query()
            ->whereIn('status', self::TERMINAL_STATUSES)
            ->where(function (Builder $query) use ($cutoffDate): void {
                $query
                    ->where('finished_at', '<', $cutoffDate)
                    ->orWhere(function (Builder $query) use ($cutoffDate): void {
                        $query
                            ->whereNull('finished_at')
                            ->where('cancelled_at', '<', $cutoffDate);
                    })
                    ->orWhere(function (Builder $query) use ($cutoffDate): void {
                        $query
                            ->whereNull('finished_at')
                            ->whereNull('cancelled_at')
                            ->where('created_at', '<', $cutoffDate);
                    });
            });
    }
}
