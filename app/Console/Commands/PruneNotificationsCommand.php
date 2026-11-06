<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Notification\PruneNotificationsAction;
use Illuminate\Console\Command;

final class PruneNotificationsCommand extends Command
{
    protected $signature = 'notifications:prune
                            {--days= : Retention duration in days (defaults to config sakala.notifications.retention_days)}
                            {--dry-run : Simulate the prune operation without deleting records}
                            {--batch=1000 : Batch size for chunked deletion}';

    protected $description = 'Prune in-app notifications older than the retention threshold';

    public function handle(PruneNotificationsAction $action): int
    {
        $daysOption = $this->option('days');
        $retentionDays = $daysOption !== null
            ? (int) $daysOption
            : (int) config('sakala.notifications.retention_days', 90);
        $dryRun = (bool) $this->option('dry-run');

        $result = $action->handle($retentionDays, $dryRun, (int) $this->option('batch'));

        $this->info(sprintf(
            $dryRun
                ? '[DRY RUN] %d notifications would be pruned (retention: %d days).'
                : 'Pruned %d notifications (retention: %d days).',
            $result['pruned'],
            $result['retention_days'],
        ));

        return self::SUCCESS;
    }
}
