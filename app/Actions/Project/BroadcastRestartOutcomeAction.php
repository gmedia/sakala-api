<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Deployment\AllocateDeploymentRealtimeSequenceAction;
use App\Enums\ProjectRestartState;
use App\Enums\RuntimeStatus;
use App\Events\Deployment\DeploymentUpdated;
use App\Models\AgentCommand;
use App\Models\Deployment;

/**
 * Tell the Console that a member's restart finished, on the channel it already
 * follows for the restarted deployment (`deployment.updated`), even when
 * `runtime_status` did not change: the event carries `restart` with the
 * command and its outcome next to `project_runtime_status`, so the Console
 * can drop its pending state without polling.
 */
final class BroadcastRestartOutcomeAction
{
    public function __construct(
        private readonly AllocateDeploymentRealtimeSequenceAction $allocateRealtimeSequence,
    ) {}

    /** The caller holds the deployment's row lock, like every realtime sequence allocation. */
    public function handle(Deployment $deployment, AgentCommand $command, RuntimeStatus $runtimeStatus, ProjectRestartState $outcome): void
    {
        $sequence = $this->allocateRealtimeSequence->handle($deployment);

        DeploymentUpdated::dispatch(
            deploymentId: $deployment->id,
            payload: [
                ...DeploymentUpdated::payloadFor($deployment, $sequence),
                'project_runtime_status' => $runtimeStatus->value,
                'restart' => [
                    'command_id' => $command->id,
                    'outcome' => $outcome->value,
                ],
            ],
        );
    }
}
