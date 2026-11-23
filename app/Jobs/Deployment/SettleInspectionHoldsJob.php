<?php

declare(strict_types=1);

namespace App\Jobs\Deployment;

use App\Services\Deployment\DeploymentInspectionHold;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued after the transaction that records a project's inspection outcome,
 * when a deployment of that project waits for it, so the node's report never
 * waits for deployments.
 */
final class SettleInspectionHoldsJob implements ShouldQueue
{
    use Queueable;

    /** The minute sweep settles whatever a failed run left held. */
    public int $tries = 1;

    public function __construct(
        public readonly string $projectId,
    ) {}

    public function handle(DeploymentInspectionHold $hold): void
    {
        $hold->settleProject($this->projectId);
    }
}
