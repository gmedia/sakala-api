<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Exceptions\Runtime\DeploymentAlreadyFinishedException;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a deployment. A command no node holds is closed on the spot; one a
 * node holds only records the intent, which the agent learns from its next
 * poll (docs/AGENT_API.md, "Pembatalan command").
 *
 * The two paths take locks in different orders on purpose. Claim holds the
 * deployment before it updates the Pending command, so the Pending path does
 * the same; complete and fail hold the command before the deployment, so the
 * running path does the same. Either way the cancel cannot deadlock the agent
 * call it is racing.
 */
final class CancelDeploymentAction
{
    public function __construct(
        private readonly TransitionDeploymentAction $transitionDeployment,
    ) {}

    /**
     * @throws DeploymentAlreadyFinishedException
     */
    public function handle(Deployment $deployment, ?User $user = null): Deployment
    {
        $command = AgentCommand::query()
            ->where('deployment_id', $deployment->id)
            ->where('type', AgentCommandType::DeployProject)
            ->first();

        if ($command === null || $command->status === AgentCommandStatus::Pending) {
            $cancelled = $this->cancelUnclaimed($deployment, $command, $user);

            if ($cancelled !== null) {
                return $cancelled;
            }

            // A node claimed it between the read and the guarded update.
            $command = $command?->fresh();
        }

        return $this->requestFromAgent($deployment, $command, $user);
    }

    /** Null when the Pending command was claimed before it could be closed. */
    private function cancelUnclaimed(Deployment $deployment, ?AgentCommand $command, ?User $user): ?Deployment
    {
        return DB::transaction(function () use ($deployment, $command, $user): ?Deployment {
            $locked = Deployment::query()->whereKey($deployment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isTerminal()) {
                return $this->terminal($locked);
            }

            if ($command !== null) {
                $closed = AgentCommand::query()
                    ->whereKey($command->id)
                    ->where('status', AgentCommandStatus::Pending->value)
                    ->update([
                        'status' => AgentCommandStatus::Cancelled->value,
                        'error_code' => 'deployment_cancelled',
                        'error_message' => 'The deployment was cancelled before a node claimed it.',
                        'updated_at' => now(),
                    ]);

                if ($closed === 0) {
                    return null;
                }
            }

            $this->audit($locked, $user, 'deployment.cancelled');

            return $this->transitionDeployment->handleWithinTransaction(
                deployment: $locked,
                nextStatus: DeploymentStatus::Cancelled,
            );
        });
    }

    private function requestFromAgent(Deployment $deployment, ?AgentCommand $command, ?User $user): Deployment
    {
        return DB::transaction(function () use ($deployment, $command, $user): Deployment {
            $lockedCommand = $command === null
                ? null
                : AgentCommand::query()->whereKey($command->id)->lockForUpdate()->firstOrFail();

            $locked = Deployment::query()->whereKey($deployment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isTerminal()) {
                return $this->terminal($locked);
            }

            // Repeating the request keeps the first timestamp and adds nothing.
            if ($locked->cancel_requested_at !== null) {
                return $locked;
            }

            $now = now();
            $lockedCommand?->update(['cancel_requested_at' => $now]);
            $locked->update(['cancel_requested_at' => $now]);

            $this->audit($locked, $user, 'deployment.cancel_requested');

            return $locked;
        });
    }

    /**
     * Idempotent for a deployment already cancelled; anything else that ended
     * has nothing left to cancel and says so.
     */
    private function terminal(Deployment $deployment): Deployment
    {
        if ($deployment->status === DeploymentStatus::Cancelled) {
            return $deployment;
        }

        throw new DeploymentAlreadyFinishedException($deployment->status);
    }

    private function audit(Deployment $deployment, ?User $user, string $action): void
    {
        AuditEvent::create([
            'actor_type' => $user !== null ? User::class : 'system',
            'actor_id' => $user !== null ? (string) $user->id : 'project-delete',
            'action' => $action,
            'subject_type' => Deployment::class,
            'subject_id' => $deployment->id,
            'metadata' => [
                'project_id' => $deployment->project_id,
                'status' => $deployment->status->value,
            ],
        ]);
    }
}
