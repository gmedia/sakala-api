<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Schedule\DispatchSchedulesAction;
use Illuminate\Console\Command;

final class DispatchSchedulesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'schedules:dispatch
                            {--limit=200 : Maximum waiting runs and due schedules to handle in this run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Record the due slots of project schedules and start their runs (ADR-022)';

    public function handle(DispatchSchedulesAction $action): int
    {
        $counts = $action->handle(limit: (int) $this->option('limit'));

        $this->info("Started {$counts['started']}, waiting {$counts['waiting']}, skipped {$counts['skipped']}, missed {$counts['missed']} schedule run(s).");

        return self::SUCCESS;
    }
}
