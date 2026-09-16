<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\CollectUsageSignalsAction;
use Illuminate\Console\Command;

final class CollectUsageSignalsCommand extends Command
{
    protected $signature = 'usage:signals-collect';

    protected $description = 'Collect bounded operational signals from existing tables';

    public function handle(
        CollectUsageSignalsAction $action,
    ): int {
        // Use an aligned previous-hour window matching the scheduler cadence.
        // The scheduler runs at :00 every hour; we collect the completed hour
        // so each run covers [N-1:00, N:00) with no overlap against adjacent
        // runs. collect_interval_hours config is intentionally removed —
        // cadence is locked to hourly.
        $now = now()->toImmutable();
        $from = $now->copy()->subHour()->startOfHour();
        $to = $now->startOfHour();

        $action->handle($from, $to);

        $this->info(sprintf(
            'Usage signals collected for window [%s → %s].',
            $from->toIso8601String(),
            $to->toIso8601String(),
        ));

        return self::SUCCESS;
    }
}
