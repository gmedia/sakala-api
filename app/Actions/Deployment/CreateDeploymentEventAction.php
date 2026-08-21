<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Enums\DeploymentEventLevel;
use App\Events\Deployment\DeploymentEventCreated;
use App\Models\Deployment;

final class CreateDeploymentEventAction
{
    /** @param array<string, mixed>|null $metadata */
    public function handle(
        Deployment $deployment,
        DeploymentEventLevel $level,
        string $type,
        string $message,
        ?array $metadata = null
    ): void {
        $sequence = (int) $deployment->events()->max('sequence') + 1;

        $deploymentEvent = $deployment->events()->create([
            'sequence' => $sequence,
            'level' => $level,
            'type' => $type,
            'message' => $message,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);

        DeploymentEventCreated::dispatch($deploymentEvent);
    }
}
