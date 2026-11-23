<?php

declare(strict_types=1);

namespace App\Jobs\Attachment;

use App\Actions\Attachment\RedeployAfterCredentialRotationAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued after the transaction that records a completed credential rotation
 * (a database password or a bucket key), so the node's report never waits
 * for deployments or GitHub.
 */
final class RedeployAfterCredentialRotationJob implements ShouldQueue
{
    use Queueable;

    /** Each project is isolated in the action, and the outcome is recorded once; a retry would add nothing. */
    public int $tries = 1;

    public function __construct(
        public readonly string $commandId,
    ) {}

    public function handle(RedeployAfterCredentialRotationAction $action): void
    {
        $action->handle($this->commandId);
    }
}
