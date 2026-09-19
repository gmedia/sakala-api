<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Actions\Admin\CreateStopProjectCommandAction;
use App\Actions\Deployment\TransitionDeploymentAction;
use App\Data\Agent\DeployProjectResultData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\RuntimeStatus;
use App\Exceptions\Agent\CommandConflictException;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class CompleteAgentCommandAction
{
    public function __construct(
        private readonly TransitionDeploymentAction $transitionDeployment,
        private readonly CreateStopProjectCommandAction $createStopProjectCommand,
    ) {}

    /**
     * Mark a claimed/running command as succeeded.
     * Returns true when the transition was performed, false when the command
     * is already in terminal Succeeded state (idempotent repeat).
     *
     * @param  array<string, mixed>|null  $result
     *
     * @throws CommandConflictException when the transition is illegal or the
     *                                  command does not belong to the caller.
     */
    public function handle(AgentNode $agent, string $commandId, ?array $result = null): bool
    {
        DB::transaction(function () use ($agent, $commandId, $result): void {
            $command = AgentCommand::query()
                ->whereKey($commandId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($command->agent_node_id !== $agent->id) {
                throw new CommandConflictException($command);
            }

            if ($command->status->value === AgentCommandStatus::Succeeded->value) {
                return;
            }

            if (! in_array($command->status->value, [
                AgentCommandStatus::Claimed->value,
                AgentCommandStatus::Running->value,
            ], true)) {
                throw new CommandConflictException($command);
            }

            $command->update([
                'status' => AgentCommandStatus::Succeeded,
                'completed_at' => now(),
                'result' => $result,
            ]);

            if ($command->type === AgentCommandType::DeployProject && $command->deployment_id !== null) {
                $this->completeDeployment($agent, $command, DeployProjectResultData::fromArray($result));
            }

            if ($command->type->isNodeLevel()) {
                // Result keys only: never echo command payload into the audit trail.
                AuditEvent::create([
                    'actor_type' => AgentNode::class,
                    'actor_id' => $agent->id,
                    'action' => 'agent.command.completed',
                    'subject_type' => AgentCommand::class,
                    'subject_id' => $command->id,
                    'metadata' => [
                        'type' => $command->type->value,
                        'agent_node_id' => $command->agent_node_id,
                        'result_keys' => array_keys($result ?? []),
                    ],
                ]);
            }

            if (in_array($command->type, [
                AgentCommandType::StopProject,
                AgentCommandType::SleepProject,
            ], true)) {
                $project = Project::query()
                    ->lockForUpdate()
                    ->findOrFail($command->project_id);

                $currentDeployment = Deployment::query()
                    ->where('project_id', $project->id)
                    ->where('status', DeploymentStatus::Succeeded)
                    ->whereNotNull('agent_node_id')
                    ->orderByDesc('sequence')
                    ->first();

                $isCurrentWorkload = $command->deployment_id !== null && $currentDeployment?->id === $command->deployment_id;

                if ($isCurrentWorkload) {
                    $project->update([
                        'runtime_status' => RuntimeStatus::Stopped,
                    ]);
                }

                $action = $command->type === AgentCommandType::StopProject
                    ? 'project.stop_completed'
                    : 'project.suspend_completed';

                AuditEvent::create([
                    'actor_type' => AgentNode::class,
                    'actor_id' => $agent->id,
                    'action' => $action,
                    'subject_type' => Project::class,
                    'subject_id' => $project->id,
                    'metadata' => [
                        'command_id' => $command->id,
                        'deployment_id' => $command->deployment_id,
                        'agent_node_id' => $command->agent_node_id,
                        'outcome' => 'succeeded',
                    ],
                ]);
            }
        });

        // Reached here only if not already Succeeded (idempotent path returns early)
        return true;
    }

    /**
     * The agent's completion is authoritative for a deployment: record what
     * it applied, mark the deployment succeeded (which flips the project to
     * running), and when the agent could not finish post-commit cleanup,
     * explicitly stop every superseded workload on the same node.
     */
    private function completeDeployment(AgentNode $agent, AgentCommand $command, DeployProjectResultData $result): void
    {
        /** @var Deployment $deployment */
        $deployment = Deployment::query()
            ->whereKey($command->deployment_id)
            ->lockForUpdate()
            ->firstOrFail();

        $deployment->update([
            'applied_resources' => $result->appliedResources?->toArray(),
            'finalization_deferred' => $result->finalizationDeferred,
            'finalization_deferred_reason' => $result->finalizationDeferredReason,
        ]);

        if ($deployment->status->isTerminal()) {
            // Expired or failed by the control plane before the agent reported;
            // keep the record, never resurrect it.
            AuditEvent::create([
                'actor_type' => AgentNode::class,
                'actor_id' => $agent->id,
                'action' => 'deployment.completion_after_terminal',
                'subject_type' => Deployment::class,
                'subject_id' => $deployment->id,
                'metadata' => [
                    'command_id' => $command->id,
                    'status' => $deployment->status->value,
                ],
            ]);

            return;
        }

        // The agent reports phases itself but has no "succeeded" event; the
        // API writes the closing timeline entry so the console sees it.
        $deployment = $this->transitionDeployment->handleWithinTransaction(
            deployment: $deployment,
            nextStatus: DeploymentStatus::Succeeded,
        );

        if (! $result->finalizationDeferred) {
            return;
        }

        $this->stopSupersededDeployments($agent, $command, $deployment, $result);
    }

    private function stopSupersededDeployments(
        AgentNode $agent,
        AgentCommand $command,
        Deployment $deployment,
        DeployProjectResultData $result,
    ): void {
        /** @var Collection<int, Deployment> $superseded */
        $superseded = Deployment::query()
            ->where('project_id', $deployment->project_id)
            ->where('agent_node_id', $deployment->agent_node_id)
            ->where('status', DeploymentStatus::Succeeded)
            ->where('sequence', '<', $deployment->sequence)
            ->whereKeyNot($deployment->id)
            ->orderBy('sequence')
            ->get();

        $stopCommandIds = [];

        foreach ($superseded as $prior) {
            $stop = $this->createStopProjectCommand->handle(
                deployment: $prior,
                reason: 'finalization_deferred:'.($result->finalizationDeferredReason->value ?? 'unknown'),
                idempotencyKey: "deferred-finalization:{$deployment->id}:{$prior->id}",
            );

            $stopCommandIds[] = $stop->id;
        }

        AuditEvent::create([
            'actor_type' => AgentNode::class,
            'actor_id' => $agent->id,
            'action' => 'deployment.finalization_deferred',
            'subject_type' => Deployment::class,
            'subject_id' => $deployment->id,
            'metadata' => [
                'command_id' => $command->id,
                'agent_node_id' => $deployment->agent_node_id,
                'reason' => $result->finalizationDeferredReason?->value,
                'stop_command_ids' => $stopCommandIds,
            ],
        ]);
    }
}
