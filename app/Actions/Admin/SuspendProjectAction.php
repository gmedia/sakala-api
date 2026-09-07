<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\ProjectControlData;
use App\Data\Admin\ProjectControlResultData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectControlAction;
use App\Enums\ProjectStatus;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectControlRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SuspendProjectAction
{
    public function __construct(
        private readonly CreateSleepProjectCommandAction $createSleepProjectCommandAction,
    ) {}

    private function findExistingRequest(
        ProjectControlData $data,
    ): ?ProjectControlRequest {
        if ($data->idempotencyKey === null) {
            return null;
        }

        return ProjectControlRequest::query()
            ->where('idempotency_key', $data->idempotencyKey)
            ->first();
    }

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
        ProjectControlRequest $request,
        Project $project,
        User $user,
        ProjectControlAction $action,
        ProjectControlData $data,
    ): void {
        if (
            $request->project_id !== $project->id
            || $request->action !== $action
            || $request->reason !== $data->reason
            || $request->actor_type !== User::class
            || (string) $request->actor_id !== (string) $user->id
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

            $existingRequest = $this->findExistingRequest($data);

            if ($existingRequest !== null) {
                $this->assertIdempotentRetry(
                    request: $existingRequest,
                    project: $lockedProject,
                    user: $user,
                    action: ProjectControlAction::Suspend,
                    data: $data,
                );

                /** @var array<string, mixed> $responseContext */
                $responseContext = $existingRequest->response_context ?? [];

                return new ProjectControlResultData(
                    project: $lockedProject->refresh(),
                    command: $existingRequest->agentCommand,
                    responseContext: $responseContext,
                );
            }

            $existingCommand = $this->findExistingCommand($data);

            if ($existingCommand !== null) {
                abort(409, 'Idempotency key has already been used for a different request.');
            }

            if ($lockedProject->status === ProjectStatus::Suspended) {
                abort(409, 'Project is already suspended');
            }

            $pendingSuspendCommand = $this->findPendingSuspendCommand(
                $lockedProject,
            );

            if ($pendingSuspendCommand !== null) {
                abort(409, 'A suspended command is already in progress for this project.');
            }

            $deployment = Deployment::query()
                ->where('project_id', $lockedProject->id)
                ->where('status', DeploymentStatus::Succeeded)
                ->whereNotNull('agent_node_id')
                ->orderByDesc('sequence')
                ->first();

            $lockedProject->update([
                'status' => ProjectStatus::Suspended,
            ]);

            $responseContext = [
                'project_status' => $lockedProject->status->value,
                'runtime_status' => $lockedProject->runtime_status->value,
            ];

            $idempotencyKey = $data->idempotencyKey ?? Str::uuid()->toString();

            $controlRequest = ProjectControlRequest::create([
                'project_id' => $lockedProject->id,
                'action' => ProjectControlAction::Suspend,
                'idempotency_key' => $idempotencyKey,
                'actor_type' => User::class,
                'actor_id' => $user->id,
                'reason' => $data->reason,
                'response_context' => $responseContext,
            ]);

            $command = null;

            if ($deployment !== null) {
                $command = $this->createSleepProjectCommandAction->handle(
                    deployment: $deployment,
                    user: $user,
                    reason: $data->reason,
                    idempotencyKey: $idempotencyKey,
                    responseContext: $responseContext,
                );

                $controlRequest->update([
                    'agent_command_id' => $command->id,
                ]);
            }

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
                    'command_id' => $command?->id,
                    'deployment_id' => $deployment?->id,
                    'agent_node_id' => $deployment?->agent_node_id,
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
