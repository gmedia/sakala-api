<?php

declare(strict_types=1);

namespace App\Events\Deployment;

use App\Models\Deployment;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class DeploymentUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        private readonly Deployment $deployment
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("deployment.{$this->deployment->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'deployment.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'deployment_id' => $this->deployment->id,
            'project_id' => $this->deployment->project_id,
            'sequence' => $this->deployment->sequence,
            'status' => $this->deployment->status->value,
            'trigger' => $this->deployment->trigger->value,
            'branch' => $this->deployment->branch,
            'commit_sha' => $this->deployment->commit_sha,
            'commit_message' => $this->deployment->commit_message,
            'started_at' => $this->deployment->started_at?->toISOString(),
            'finished_at' => $this->deployment->finished_at?->toISOString(),
        ];
    }
}
