<?php

declare(strict_types=1);

namespace App\Events\Deployment;

use App\Models\DeploymentEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class DeploymentEventCreated implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        private readonly DeploymentEvent $deploymentEvent
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("deployment.{$this->deploymentEvent->deployment_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'deployment.event.created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'deployment_id' => $this->deploymentEvent->deployment_id,
            'sequence' => $this->deploymentEvent->sequence,
            'level' => $this->deploymentEvent->level->value,
            'type' => $this->deploymentEvent->type,
            'message' => $this->deploymentEvent->message,
            'occurred_at' => $this->deploymentEvent->occurred_at?->toISOString(),
        ];
    }
}
