<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\ProjectControlData;
use App\Data\Admin\ProjectControlResultData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\ProjectStatus;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SuspendProjectAction
{
    private function findExistingCommand(
        ProjectControlData $data,
    ): ?AgentCommand {
        if ($data->idempotencyKey === null) {
            return null;
        }

        return AgentCommand::query()
            ->where('idempotency_key', $data->idempotencyKey)
            ->first();
    }

    private function assertIdempotentRetry(
        AgentCommand $command,
        Project $project,
        User $user,
        ProjectControlData $data,
    ): void {
        $context = $command->request_context ?? [];

        if (
            $command->project_id !== $project->id
            || $command->type !== AgentCommandType::SleepProject
            || ($context['reason'] ?? null) !== $data->reason
            || ($context['actor_type'] ?? null) !== User::class
            || ($context['actor_id'] ?? null) !== (string) $user->id
        ) {
            abort(
                409,
                'Idempotency key has already been used for a different request.',
            );
        }
    }

    private function findPendingSuspendCommand(
        Project $project,
    ): ?AgentCommand {
        return AgentCommand::query()
            ->where('project_id', $project->id)
            ->where('type', AgentCommandType::SleepProject)
            ->whereIn('status', [
                AgentCommandStatus::Pending,
                AgentCommandStatus::Claimed,
                AgentCommandStatus::Running,
            ])
            ->latest('created_at')
            ->first();
    }

    public function handle(
        Project $project,
        User $user,
        ProjectControlData $data,
    ): ProjectControlResultData {
        return DB::transaction(function () use (
            $project,
            $user,
            $data,
        ): ProjectControlResultData {
            /** @var Project $lockedProject */
            $lockedProject = Project::query()
                ->lockForUpdate()
                ->findOrFail($project->id);

            $existingCommand = $this->findExistingCommand($data);

            if ($existingCommand !== null) {
                $this->assertIdempotentRetry(
                    command: $existingCommand,
                    project: $lockedProject,
                    user: $user,
                    data: $data,
                );

                return new ProjectControlResultData(
                    project: $lockedProject->refresh(),
                    command: $existingCommand,
                    responseContext: $existingCommand->response_context,
                );
            }

            if ($lockedProject->status === ProjectStatus::Suspended) {
                abort(409, 'Project is already suspended');
            }

            $pendingSuspendCommand = $this->findPendingSuspendCommand(
                $lockedProject,
            );

            if ($pendingSuspendCommand !== null) {
                abort(
                    409,
                    'A suspend command is already in progress for this project.',
                );
            }

            $deployment = Deployment::query()
                ->where('project_id', $lockedProject->id)
                ->whereNotNull('agent_node_id')
                ->active()
                ->orderByDesc('sequence')
                ->first();

            if (
                $deployment === null
                || $deployment->agent_node_id === null
            ) {
                abort(
                    409,
                    'Project does not have an active deployment target.',
                );
            }

            $lockedProject->update([
                'status' => ProjectStatus::Suspended,
            ]);

            $responseContext = [
                'project_status' => $lockedProject->status->value,
                'runtime_status' => $lockedProject->runtime_status->value,
            ];

            $command = AgentCommand::create([
                'project_id' => $lockedProject->id,
                'deployment_id' => $deployment->id,
                'agent_node_id' => $deployment->agent_node_id,
                'type' => AgentCommandType::SleepProject,
                'status' => AgentCommandStatus::Pending,

                // Runtime payload must remain empty.
                'payload' => [],

                // Control-plane request identity/context.
                'request_context' => [
                    'reason' => $data->reason,
                    'actor_type' => User::class,
                    'actor_id' => (string) $user->id,
                ],

                // Snapshot of the response represented by the
                // original request.
                'response_context' => $responseContext,

                'idempotency_key' => $data->idempotencyKey
                    ?? Str::uuid()->toString(),

                'available_at' => now(),
            ]);

            AuditEvent::create([
                'actor_type' => User::class,
                'actor_id' => $user->id,
                'action' => 'project.suspend_requested',
                'subject_type' => Project::class,
                'subject_id' => $lockedProject->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'metadata' => [
                    'reason' => $data->reason,
                    'command_id' => $command->id,
                    'deployment_id' => $deployment->id,
                    'agent_node_id' => $deployment->agent_node_id,
                ],
            ]);

            return new ProjectControlResultData(
                project: $lockedProject->refresh(),
                command: $command,
                responseContext: $responseContext,
            );
        });
    }
}
