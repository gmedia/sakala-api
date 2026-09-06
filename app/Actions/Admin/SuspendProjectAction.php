<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\ProjectControlData;
use App\Data\Admin\ProjectControlResultData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SuspendProjectAction
{
    private function findExistingCommand(
        Project $project,
        ProjectControlData $data,
    ): ?AgentCommand {

        if ($data->idempotencyKey === null) {
            return null;
        }

        return AgentCommand::query()
            ->where('idempotency_key', $data->idempotencyKey)
            ->first();
    }

    public function handle(
        Project $project,
        User $user,
        ProjectControlData $data,
    ): ProjectControlResultData {
        return DB::transaction(function () use ($project, $user, $data): ProjectControlResultData {
            $project = Project::query()
                ->lockForUpdate()
                ->findOrFail($project->id);

            $existingCommand = $this->findExistingCommand($project, $data);

            if ($existingCommand !== null) {
                if ($existingCommand->project_id !== $project->id || $existingCommand->type !== AgentCommandType::SleepProject) {
                    abort(409, 'Idempotency key has already been used for a different command.');
                }

                return new ProjectControlResultData(
                    project: $project->refresh(),
                    command: $existingCommand
                );
            }

            if ($project->status === ProjectStatus::Suspended) {
                abort(409, 'Project is already suspended');
            }

            $project->update([
                'status' => ProjectStatus::Suspended,
                'runtime_status' => RuntimeStatus::Stopped,
            ]);

            $command = AgentCommand::create([
                'project_id' => $project->id,
                'type' => AgentCommandType::SleepProject,
                'status' => AgentCommandStatus::Pending,
                'payload' => [
                    'reason' => $data->reason,
                ],
                'idempotency_key' => $data->idempotencyKey ?? Str::uuid()->toString(),
                'available_at' => now(),
            ]);

            AuditEvent::create([
                'actor_type' => User::class,
                'actor_id' => $user->id,
                'action' => 'project.suspended',
                'subject_type' => Project::class,
                'subject_id' => $project->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'metadata' => [
                    'reason' => $data->reason,
                ],
            ]);

            return new ProjectControlResultData(
                project: $project->refresh(),
                command: $command
            );
        });
    }
}
