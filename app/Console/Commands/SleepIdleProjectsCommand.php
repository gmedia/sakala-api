<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Project\SleepIdleProjectsAction;
use Illuminate\Console\Command;

final class SleepIdleProjectsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'projects:sleep-idle';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Put running projects to sleep after their workspace idle window without a request';

    public function handle(SleepIdleProjectsAction $action): int
    {
        if (! $action->enabled()) {
            $this->info('Idle sleep is off: SAKALA_PROJECT_IDLE_SLEEP_MINUTES is 0 and no limit profile sets a window.');

            return self::SUCCESS;
        }

        $created = $action->handle();

        $this->info("Asked {$created} idle project(s) to sleep.");

        return self::SUCCESS;
    }
}
