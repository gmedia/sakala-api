<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Project\RollUpProjectMetricsAction;
use Illuminate\Console\Command;

final class RollUpProjectMetricsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'projects:roll-up-metrics';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Roll per-minute project metrics up into hourly rows and prune both past their retention';

    public function handle(RollUpProjectMetricsAction $action): int
    {
        $minuteRetentionHours = (int) config('sakala.metrics.minute_retention_hours');
        $hourlyRetentionDays = (int) config('sakala.metrics.hourly_retention_days');

        $result = $action->handle($minuteRetentionHours, $hourlyRetentionDays);

        $this->info(sprintf(
            'Rolled up %d hourly row(s); pruned %d minute row(s) older than %d hours and %d hourly row(s) older than %d days.',
            $result['hours'],
            $result['pruned_minutes'],
            $minuteRetentionHours,
            $result['pruned_hours'],
            $hourlyRetentionDays,
        ));

        return self::SUCCESS;
    }
}
