<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Exceptions\Agent\CommandConflictException;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\Agent\AgentCommandEligibilityService;
use App\Services\Agent\ProjectCommandEligibilityService;
use Illuminate\Support\Facades\DB;

final class ClaimAgentCommandAction
{
    public function __construct(
        private readonly AgentCommandEligibilityService $eligibility,
        private readonly ProjectCommandEligibilityService $projectEligibility,
    ) {}

    /**
     * Atomically claim a pending command for the requesting agent node.
     * Returns the claimed command, or null when the command is not claimable
     * (state/eligibility conflict).
     *
     * The node is re-fetched under its row lock (not the middleware's copy)
     * because its state can change between the agent's poll and its claim
     * (e.g. the node went draining or lost capabilities). Polling gives no
     * ownership, so a node that is no longer eligible must fail the claim.
     * The lock serialises claims with admin lifecycle changes, offline
     * derivation, and heartbeats on the same node row: whichever acquires
     * it first defines the order — a claim that wins is legitimately
     * in-flight before a drain; a drain that wins makes the claim observe
     * the new intent and conflict.
     *
     * The transition is a single guarded UPDATE (WHERE status = Pending) —
     * the atomic primitive endorsed by the agent contract. No two processes
     * can ever flip the same row Pending -> Claimed: the winner observes one
     * affected row, every loser observes zero and conflicts. This is safe on
     * both PostgreSQL and SQLite without relying on row-lock semantics.
     *
     * @throws CommandConflictException
     */
    public function handle(AgentNode $agent, string $commandId): AgentCommand
    {
        $command = DB::transaction(function () use ($agent, $commandId): AgentCommand {
            // Re-read the node under lock: middleware loaded it at the
            // start of the request, and a heartbeat or an admin drain may
            // have changed its status, capabilities, or desired state since.
            $node = AgentNode::query()
                ->whereKey($agent->id)
                ->lockForUpdate()
                ->firstOrFail();

            $command = AgentCommand::query()
                ->whereKey($commandId)
                ->firstOrFail();

            $deployment = null;

            if ($command->deployment_id !== null) {
                $deployment = Deployment::query()
                    ->whereKey($command->deployment_id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $this->assertClaimable($command, $node);

            if ($command->type === AgentCommandType::ReconcileWorkload
                && $deployment !== null
                && $this->cancelSupersededReconcile($command, $deployment)) {
                // Committed as Cancelled; the conflict is raised after commit.
                return $command->fresh();
            }

            $updated = AgentCommand::query()
                ->whereKey($command->id)
                ->where('status', AgentCommandStatus::Pending->value)
                ->update([
                    'status' => AgentCommandStatus::Claimed->value,
                    'claimed_at' => now(),
                    'lease_expires_at' => now()->addSeconds($this->leaseSeconds($command)),
                    'attempts' => $command->attempts + 1,
                    'agent_node_id' => $command->agent_node_id ?? $node->id,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                // Lost the race: another process claimed the row first.
                throw new CommandConflictException($command->fresh());
            }

            if ($command->type === AgentCommandType::DeployProject && $deployment !== null) {
                $deployment->update([
                    'agent_node_id' => $node->id,
                ]);
            }

            return $command->fresh();
        });

        if ($command->status === AgentCommandStatus::Cancelled) {
            throw new CommandConflictException($command);
        }

        return $command;
    }

    /**
     * A reconciliation may mutate the project's route, which is shared by
     * all of the project's deployments on the node. If a newer deployment
     * became current after the command was created, executing it would point
     * the route back at a superseded container. Cancel it instead of leaving
     * it Pending forever; the caller raises the conflict once the
     * cancellation is committed. Returns true when the command was cancelled.
     */
    private function cancelSupersededReconcile(AgentCommand $command, Deployment $deployment): bool
    {
        $currentId = Deployment::query()
            ->where('project_id', $deployment->project_id)
            ->where('status', DeploymentStatus::Succeeded)
            ->whereNotNull('agent_node_id')
            ->orderByDesc('sequence')
            ->value('id');

        $inFlight = Deployment::query()
            ->where('project_id', $deployment->project_id)
            ->active()
            ->exists();

        if ($currentId === $deployment->id && ! $inFlight) {
            return false;
        }

        $command->update([
            'status' => AgentCommandStatus::Cancelled,
            'error_code' => 'reconcile_target_superseded',
            'error_message' => 'The deployment targeted by this reconciliation is no longer current.',
        ]);

        AuditEvent::create([
            'actor_type' => 'system',
            'actor_id' => 'claim',
            'action' => 'project.reconcile_cancelled',
            'subject_type' => Project::class,
            'subject_id' => $deployment->project_id,
            'metadata' => [
                'command_id' => $command->id,
                'deployment_id' => $deployment->id,
                'current_deployment_id' => $currentId,
            ],
        ]);

        return true;
    }

    /**
     * The lease is the agent's execution deadline for this command plus a
     * grace window. Past it, the control plane treats the command as
     * abandoned and recovers it (see ExpireAgentCommandsAction).
     */
    private function leaseSeconds(AgentCommand $command): int
    {
        $timeout = $command->payload['timeouts']['command_timeout_seconds'] ?? null;

        if (! is_int($timeout) || $timeout <= 0) {
            $timeout = (int) config('sakala.pilot_limits.timeouts.command_timeout_seconds', 900);
        }

        return $timeout + (int) config('sakala.agent.lease_grace_seconds', 60);
    }

    /**
     * @throws CommandConflictException
     */
    private function assertClaimable(AgentCommand $command, AgentNode $node): void
    {
        if ($command->status->value !== AgentCommandStatus::Pending->value) {
            throw new CommandConflictException($command);
        }

        if (! $this->eligibility->nodeIsEligibleFor($node, $command->type)) {
            throw new CommandConflictException($command);
        }

        if ($command->available_at > now()) {
            throw new CommandConflictException($command);
        }

        if ($command->expires_at !== null && $command->expires_at < now()) {
            throw new CommandConflictException($command);
        }

        if (! $this->eligibility->commandIsScopedToNode($command, $node)) {
            throw new CommandConflictException($command);
        }

        if ($command->project_id !== null) {
            $project = Project::query()
                ->whereKey($command->project_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->projectEligibility->isEligible(
                $project,
                $command->type,
            )) {
                throw new CommandConflictException($command);
            }
        }
    }
}
