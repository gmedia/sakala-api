<?php

declare(strict_types=1);

namespace App\Events\Deployment;

use App\Models\Deployment;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

final class DeploymentUpdated implements ShouldBroadcast
{
    use Dispatchable;

    public bool $afterCommit = true;

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly array $payload,
        private readonly string $deploymentId,
    ) {}

    /** @return array<string, mixed> */
    public static function payloadFor(Deployment $deployment, int $sequence): array
    {
        return [
            'deployment_id' => $deployment->id,
            'project_id' => $deployment->project_id,
            'sequence' => $sequence,
            'status' => $deployment->status->value,
            'trigger' => $deployment->trigger->value,
            'branch' => $deployment->branch,
            'commit_sha' => $deployment->commit_sha,
            'commit_message' => $deployment->commit_message,
            'started_at' => $deployment->started_at?->toISOString(),
            'finished_at' => $deployment->finished_at?->toISOString(),
        ];
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("deployment.{$this->deploymentId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'deployment.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
