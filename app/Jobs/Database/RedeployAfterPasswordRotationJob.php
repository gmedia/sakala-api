<?php

declare(strict_types=1);

namespace App\Jobs\Database;

use App\Actions\Attachment\RedeployAfterCredentialRotationAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Kept only so jobs queued by API v0.11 before an upgrade still run; nothing
 * dispatches it any more (RedeployAfterCredentialRotationJob does). Remove it
 * one release after v0.12.0.
 *
 * @deprecated Use RedeployAfterCredentialRotationJob.
 */
final class RedeployAfterPasswordRotationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $commandId,
    ) {}

    public function handle(RedeployAfterCredentialRotationAction $action): void
    {
        $action->handle($this->commandId);
    }
}
