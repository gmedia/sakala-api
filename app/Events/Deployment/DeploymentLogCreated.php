<?php

declare(strict_types=1);

namespace App\Events\Deployment;

use App\Models\DeploymentLog;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class DeploymentLogCreated implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        private readonly DeploymentLog $deploymentLog,
        private readonly int $realtimeSequence
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("deployment.{$this->deploymentLog->deployment_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'deployment.log.created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'deployment_id' => $this->deploymentLog->deployment_id,
            // Orders every realtime event of the deployment.
            'sequence' => $this->realtimeSequence,
            // The log's own position, equal to `sequence` in GET .../logs, so a
            // client can dedupe a broadcast against what it already fetched.
            'log_sequence' => $this->deploymentLog->sequence,
            'stream' => $this->deploymentLog->stream->value,
            'message' => $this->deploymentLog->message,
            'recorded_at' => $this->deploymentLog->recorded_at?->toISOString(),
        ];
    }
}
