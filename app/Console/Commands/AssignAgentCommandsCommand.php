<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Agent\AssignPendingCommandsAction;
use Illuminate\Console\Command;

final class AssignAgentCommandsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent:assign-commands
                            {--limit=50 : Maximum number of commands to assign in this run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Assign unassigned pinned agent commands to an eligible runtime node';

    public function handle(AssignPendingCommandsAction $action): int
    {
        $assigned = $action->handle(limit: (int) $this->option('limit'));

        $this->info("Assigned {$assigned} agent command(s).");

        return self::SUCCESS;
    }
}
