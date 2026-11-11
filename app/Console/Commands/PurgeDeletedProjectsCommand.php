<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Project\PurgeDeletedProjectsAction;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class PurgeDeletedProjectsCommand extends Command
{
    protected $signature = 'projects:purge-deleted
                            {--days= : Retention duration in days (defaults to config sakala.project.deleted_retention_days)}
                            {--dry-run : Report what would happen without changing anything}
                            {--batch=100 : How many projects to read at a time}';

    protected $description = 'Permanently delete projects whose soft delete is older than the retention window';

    public function handle(PurgeDeletedProjectsAction $action): int
    {
        $daysOption = $this->option('days');

        try {
            $result = $action->handle(
                days: $daysOption !== null ? (int) $daysOption : null,
                dryRun: (bool) $this->option('dry-run'),
                batchSize: (int) $this->option('batch'),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $modeLabel = $result->isDryRun ? '[DRY RUN] ' : '';

        $this->info("{$modeLabel}Purge finished for projects deleted more than {$result->retentionDays} days ago (cutoff: {$result->cutoffDate->toIso8601String()}).");

        $this->table(
            ['Metric', 'Count'],
            [
                ['Projects '.($result->isDryRun ? 'Eligible' : 'Purged'), (string) $result->purgedCount],
                ['Projects Waiting For A Node', (string) $result->waitingCount],
                ['Projects Failed', (string) $result->failedCount],
            ],
        );

        return $result->failedCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
