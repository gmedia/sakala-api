<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Actions\Deployment\TransitionDeploymentAction;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentFailureClassifier;
use App\Services\Project\ProjectInspectionOutcomeService;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic recovery for commands the agent never finished. Two
 * deadlines apply: `expires_at` while a command is still Pending (it was
 * never claimed in time) and `lease_expires_at` once claimed (the agent
 * went away mid-execution). Both end in the terminal `Expired` status; the
 * deployment or inspection that depended on the command is closed with a
 * failure code so quotas are released and the console sees why.
 */
final class ExpireAgentCommandsAction
{
    public const CODE_QUEUE_EXPIRED = 'command_expired';

    public const CODE_LEASE_EXPIRED = 'command_lease_expired';

    public function __construct(
        private readonly TransitionDeploymentAction $transitionDeployment,
        private readonly DeploymentFailureClassifier $failureClassifier,
        private readonly ProjectInspectionOutcomeService $inspectionOutcome,
    ) {}

    /**
     * @return array{queue: int, lease: int}
     */
    public function handle(int $limit = 100): array
    {
        $queued = AgentCommand::query()
            ->where('status', AgentCommandStatus::Pending)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->pluck('id');

        $leased = AgentCommand::query()
            ->whereIn('status', [AgentCommandStatus::Claimed, AgentCommandStatus::Running])
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', now())
            ->orderBy('lease_expires_at')
            ->limit($limit)
            ->pluck('id');

        $counts = ['queue' => 0, 'lease' => 0];

        foreach ($queued as $id) {
            $counts['queue'] += $this->expire($id, self::CODE_QUEUE_EXPIRED);
        }

        foreach ($leased as $id) {
            $counts['lease'] += $this->expire($id, self::CODE_LEASE_EXPIRED);
        }

        return $counts;
    }

    private function expire(string $commandId, string $code): int
    {
        return DB::transaction(function () use ($commandId, $code): int {
            $command = AgentCommand::query()
                ->whereKey($commandId)
                ->lockForUpdate()
                ->first();

            // Re-check under the lock: the agent may have finished, or a claim
            // may have landed, between the scan and now.
            if ($command === null || ! $this->stillExpired($command, $code)) {
                return 0;
            }

            $command->update([
                'status' => AgentCommandStatus::Expired,
                'failed_at' => now(),
                'error_code' => $code,
                'error_message' => $code === self::CODE_LEASE_EXPIRED
                    ? 'The agent did not finish the command before its lease expired.'
                    : 'The command was not claimed before it expired.',
            ]);

            if ($command->type === AgentCommandType::DeployProject && $command->deployment_id !== null) {
                $this->failDeployment($command, $code);
            }

            if ($command->type === AgentCommandType::InspectProject) {
                // Locks the project before the stale check, like complete/fail.
                $this->inspectionOutcome->recordFailure($command, $code);
            }

            AuditEvent::create([
                'actor_type' => 'system',
                'actor_id' => 'agent:expire-commands',
                'action' => 'agent.command.expired',
                'subject_type' => AgentCommand::class,
                'subject_id' => $command->id,
                'metadata' => [
                    'type' => $command->type->value,
                    'agent_node_id' => $command->agent_node_id,
                    'deployment_id' => $command->deployment_id,
                    'code' => $code,
                ],
            ]);

            return 1;
        });
    }

    private function stillExpired(AgentCommand $command, string $code): bool
    {
        if ($code === self::CODE_QUEUE_EXPIRED) {
            return $command->status === AgentCommandStatus::Pending
                && $command->expires_at !== null
                && $command->expires_at < now();
        }

        return in_array($command->status, [AgentCommandStatus::Claimed, AgentCommandStatus::Running], true)
            && $command->lease_expires_at !== null
            && $command->lease_expires_at < now();
    }

    private function failDeployment(AgentCommand $command, string $code): void
    {
        $deployment = Deployment::query()
            ->whereKey($command->deployment_id)
            ->lockForUpdate()
            ->first();

        // Never touch a deployment the control plane or agent already closed.
        if ($deployment === null || $deployment->status->isTerminal()) {
            return;
        }

        $this->transitionDeployment->handleWithinTransaction(
            deployment: $deployment,
            nextStatus: DeploymentStatus::Failed,
            failureData: $this->failureClassifier->classify($code),
        );
    }
}
