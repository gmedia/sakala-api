<?php

declare(strict_types=1);

namespace App\Events\Deployment;

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
