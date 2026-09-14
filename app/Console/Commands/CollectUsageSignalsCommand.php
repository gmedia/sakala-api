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
        $now = now()->toImmutable();
        $intervalHours = (int) config('sakala.usage_signals.collect_interval_hours', 1);
        $from = $now->subHours($intervalHours);

        $action->handle($from, $now);

        $this->info(sprintf(
            'Usage signals collected for window [%s → %s].',
            $from->toIso8601String(),
            $now->toIso8601String(),
        ));

        return self::SUCCESS;
    }
}
