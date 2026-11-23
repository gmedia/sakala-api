<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Bucket\CheckBucketUsageAction;
use Illuminate\Console\Command;

final class CheckBucketUsageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'buckets:check-usage';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Warn owners and members once when a managed bucket reaches 80 % of its quota, and clear the mark below it';

    public function handle(CheckBucketUsageAction $action): int
    {
        $result = $action->handle();

        $this->info("Warned about {$result['warned']} bucket(s), cleared {$result['cleared']}.");

        return self::SUCCESS;
    }
}
