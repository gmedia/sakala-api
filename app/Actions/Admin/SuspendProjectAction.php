<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\ProjectControlData;
use App\Data\Admin\ProjectControlResultData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\NotificationCategory;
use App\Enums\ProjectControlAction;
use App\Enums\ProjectStatus;
use App\Enums\UsageSignalType;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectControlRequest;
use App\Models\User;
use App\Notifications\Project\ProjectSuspendedNotification;
use App\Services\Notification\UserNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class SuspendProjectAction
{
    public function __construct(
        private readonly CreateSleepProjectCommandAction $createSleepProjectCommandAction,
        private readonly RecordUsageSignalAction $recordAction,
        private readonly UserNotifier $notifier,
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
                'Idempotency key ini sudah dipakai untuk permintaan lain. Pakai key baru.',
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
                abort(409, 'Idempotency key ini sudah dipakai untuk permintaan lain. Pakai key baru.');
            }

            if ($lockedProject->status === ProjectStatus::Suspended) {
                abort(409, 'Proyek ini sudah ditangguhkan.');
            }

            $pendingSuspendCommand = $this->findPendingSuspendCommand(
                $lockedProject,
            );

            if ($pendingSuspendCommand !== null) {
                abort(409, 'Command penangguhan proyek ini masih berjalan. Tunggu sampai selesai.');
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

            $this->recordAction->handle(
                type: UsageSignalType::ManualIntervention,
                count: 1,
                scope: 'project',
                scopeId: (string) $lockedProject->id,
                tags: ['action_code' => 'manual_suspend'],
            );

            $this->notifyOwner($lockedProject, $data->reason);

            return new ProjectControlResultData(
                project: $lockedProject->refresh(),
                command: $command,
                responseContext: $responseContext,
            );
        });
    }

    /**
     * Only on a new suspension, so a retried request tells nobody twice. The
     * reason reaches the owner as the admin wrote it, so it must not name whoever
     * reported the project. The notice ignores notification preferences.
     */
    private function notifyOwner(Project $project, string $reason): void
    {
        $owner = $project->user;
        $contact = (string) config('sakala.contact.abuse_email');

        $this->notifier->send(
            $owner,
            NotificationCategory::ProjectIssue,
            'Proyek ditangguhkan',
            "{$project->name} ditangguhkan oleh admin Sakala, jadi trafiknya diputus dan belum bisa di-deploy. Alasannya: {$reason} Kalau menurutmu keputusan ini keliru, kirim email ke {$contact}.",
            $project->id,
            mandatory: true,
        );

        if (! config('sakala.email_delivery_enabled')) {
            return;
        }

        DB::afterCommit(static function () use ($owner, $project, $reason): void {
            try {
                $owner?->notify(new ProjectSuspendedNotification($project->name, $reason));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
