<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Agent\ExpireAgentCommandsAction;
use Illuminate\Console\Command;

final class ExpireAgentCommandsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent:expire-commands
                            {--limit=100 : Maximum commands per deadline type to expire in this run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire agent commands that were never claimed in time or whose lease ran out';

    public function handle(ExpireAgentCommandsAction $action): int
    {
        $counts = $action->handle(limit: (int) $this->option('limit'));

        $this->info("Expired {$counts['queue']} unclaimed and {$counts['lease']} leased agent command(s).");

        return self::SUCCESS;
    }
}
