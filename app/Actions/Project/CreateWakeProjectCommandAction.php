<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Queue a WakeProject for the node that keeps a deployment's stopped container
 * and record `project.wake_requested`. Callers decide that the project may
 * wake, check the container limit, and hold the project's row lock.
 */
final class CreateWakeProjectCommandAction
{
    /**
     * The project's WakeProject that is still queued or running, if any. A
     * second one would only start the same container twice.
     */
    public function open(Project $project): ?AgentCommand
    {
        return AgentCommand::query()
            ->where('project_id', $project->id)
            ->where('type', AgentCommandType::WakeProject)
            ->open()
            ->latest('created_at')
            ->first();
    }

    /**
     * A member's wake names the member; otherwise `$systemActorId` names the
     * system actor (a visitor of the sleeping page is not identified).
     */
    public function handle(
        Deployment $deployment,
        string $reason,
        string $source,
        ?User $user = null,
        ?string $systemActorId = null,
    ): AgentCommand {
        if ($deployment->agent_node_id === null) {
            throw new \RuntimeException(
                'Deployment must have an agent node before creating a wake command.',
            );
        }

        $actor = [
            'actor_type' => $user !== null ? User::class : AuditEvent::SYSTEM_ACTOR_TYPE,
            'actor_id' => $user !== null ? (string) $user->id : $systemActorId,
        ];

        $command = AgentCommand::create([
            'project_id' => $deployment->project_id,
            'deployment_id' => $deployment->id,
            'agent_node_id' => $deployment->agent_node_id,
            'type' => AgentCommandType::WakeProject,
            'status' => AgentCommandStatus::Pending,
            'payload' => [],
            'request_context' => [
                'reason' => $reason,
                'source' => $source,
                ...$actor,
            ],
            'idempotency_key' => "project-wake:{$deployment->project_id}:".Str::uuid()->toString(),
            'available_at' => now(),
        ]);

        AuditEvent::create([
            ...$actor,
            'action' => 'project.wake_requested',
            'subject_type' => Project::class,
            'subject_id' => $deployment->project_id,
            'metadata' => [
                'source' => $source,
                'command_id' => $command->id,
                'deployment_id' => $deployment->id,
                'agent_node_id' => $deployment->agent_node_id,
            ],
        ]);

        return $command;
    }
}
