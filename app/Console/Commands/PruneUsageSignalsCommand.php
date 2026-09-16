<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\PruneUsageSignalsAction;
use Illuminate\Console\Command;

final class PruneUsageSignalsCommand extends Command
{
    protected $signature = 'usage:signals-prune
                            {--days= : Retention duration in days (defaults to config sakala.usage_signals.retention_days)}
                            {--dry-run : Simulate the prune operation without deleting records}
                            {--batch=1000 : Batch size for chunked deletion}';

    protected $description = 'Prune usage signal records older than the retention threshold';

    public function handle(
        PruneUsageSignalsAction $action,
    ): int {
        $daysOption = $this->option('days');
        $retentionDays = $daysOption !== null
            ? (int) $daysOption
            : (int) config('sakala.usage_signals.retention_days', 30);
        $dryRun = (bool) $this->option('dry-run');
        $batch = (int) $this->option('batch');

        $result = $action->handle($retentionDays, $dryRun, $batch);

        if ($dryRun) {
            $this->info(sprintf(
                '[DRY RUN] %d usage signal records would be pruned (retention: %d days).',
                $result['pruned'],
                $result['retention_days'],
            ));
        } else {
            $this->info(sprintf(
                'Pruned %d usage signal records (retention: %d days).',
                $result['pruned'],
                $result['retention_days'],
            ));
        }

        return self::SUCCESS;
    }
}
