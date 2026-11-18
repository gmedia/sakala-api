<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Edge\CheckEdgeHealthAction;
use Illuminate\Console\Command;

/**
 * Evaluates every Sakala Edge instance and emails the admins when one
 * becomes unhealthy or recovers. Scheduled every minute in
 * routes/console.php; see docs/EDGE_API.md#pemantauan.
 */
final class CheckEdgeHealthCommand extends Command
{
    protected $signature = 'edge:check-health';

    protected $description = 'Check whether each Sakala Edge instance fetches and applies the route table, and alert admins on a change';

    public function handle(CheckEdgeHealthAction $action): int
    {
        $result = $action->handle();

        $this->info("Checked {$result->checked} Edge instance(s): {$result->becameUnhealthy} became unhealthy, {$result->recovered} recovered.");

        return self::SUCCESS;
    }
}
