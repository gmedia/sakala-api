<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Enums\LogStream;
use App\Events\Deployment\DeploymentLogCreated;
use App\Models\Deployment;

final class CreateDeploymentLogAction
{
    public function __construct(
        private readonly AllocateDeploymentRealtimeSequenceAction $allocateDeploymentRealtimeSequenceAction
    ) {}

    public function handle(
        Deployment $deployment,
        LogStream $logStream,
        string $message,
    ): void {
        $sequence = (int) $deployment->logs()->max('sequence') + 1;

        $deploymentLog = $deployment->logs()->create([
            'sequence' => $sequence,
            'stream' => $logStream,
            'message' => $message,
            'recorded_at' => now(),
        ]);

        $realtimeSequence = $this->allocateDeploymentRealtimeSequenceAction->handle($deployment);

        DeploymentLogCreated::dispatch(
            deploymentLog: $deploymentLog,
            realtimeSequence: $realtimeSequence
        );
    }
}
