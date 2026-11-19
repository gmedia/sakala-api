<?php

declare(strict_types=1);

namespace App\Jobs\Database;

use App\Actions\Database\DeployAfterDatabaseReadyAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued after the transaction that records a database as ready, when a
 * project asked to be deployed then, so the node's report never waits for
 * deployments or GitHub.
 */
final class DeployAfterDatabaseReadyJob implements ShouldQueue
{
    use Queueable;

    /** Each request is resolved once in the action, with a reason when it fails; a retry would add nothing. */
    public int $tries = 1;

    public function __construct(
        public readonly string $databaseId,
    ) {}

    public function handle(DeployAfterDatabaseReadyAction $action): void
    {
        $action->handle($this->databaseId);
    }
}
