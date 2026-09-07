<?php

declare(strict_types=1);

namespace App\Actions\Agent;

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
use Illuminate\Support\Facades\DB;

final class CompleteAgentCommandAction
{
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
                        'result' => $result,
                    ],
                ]);
            }
        });

        // Reached here only if not already Succeeded (idempotent path returns early)
        return true;
    }
}
