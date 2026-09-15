<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Deployment\PruneDeploymentLogsAction;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class PruneDeploymentLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pilot:prune-logs
                            {--days= : Retention duration in days (defaults to config sakala.pilot_limits.log_retention_days)}
                            {--dry-run : Simulate the prune operation without deleting records}
                            {--batch=1000 : Batch size for chunked deletion}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune deployment logs and events from terminal deployments older than the retention threshold';

    /**
     * Execute the console command.
     */
    public function handle(PruneDeploymentLogsAction $action): int
    {
        $daysOption = $this->option('days');
        $days = $daysOption !== null ? (int) $daysOption : null;
        $dryRun = (bool) $this->option('dry-run');
        $batch = (int) $this->option('batch');

        try {
            $result = $action->handle(
                days: $days,
                dryRun: $dryRun,
                batchSize: $batch,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $modeLabel = $result->isDryRun ? '[DRY RUN] ' : '';

        $this->info("{$modeLabel}Prune completed for deployments older than {$result->retentionDays} days (cutoff: {$result->cutoffDate->toIso8601String()}).");

        $this->table(
            ['Metric', 'Count'],
            [
                ['Affected Deployments', (string) $result->affectedDeploymentsCount],
                ['Deployment Logs '.($result->isDryRun ? 'Eligible' : 'Pruned'), (string) $result->prunedLogsCount],
                ['Deployment Events '.($result->isDryRun ? 'Eligible' : 'Pruned'), (string) $result->prunedEventsCount],
            ]
        );

        return self::SUCCESS;
    }
}
