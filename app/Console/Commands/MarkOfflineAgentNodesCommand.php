<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Agent\MarkOfflineAgentNodesAction;
use Illuminate\Console\Command;

final class MarkOfflineAgentNodesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent:mark-offline-nodes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark runtime nodes offline when their heartbeat is older than the configured window';

    public function handle(MarkOfflineAgentNodesAction $action): int
    {
        $marked = $action->handle();

        $this->info("Marked {$marked} agent node(s) offline.");

        return self::SUCCESS;
    }
}
