<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Database\EnforceDatabaseStorageQuotaAction;
use Illuminate\Console\Command;

final class EnforceDatabaseStorageQuotaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'databases:enforce-storage-quota';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Warn at 80 % of a workspace\'s managed database storage quota, lock its databases at 100 %, and unlock them below it';

    public function handle(EnforceDatabaseStorageQuotaAction $action): int
    {
        $sweep = $action->handle();

        $this->info("Warned {$sweep->warned} workspace(s), asked to lock {$sweep->lockRequested} and unlock {$sweep->unlockRequested} database(s).");

        return self::SUCCESS;
    }
}
