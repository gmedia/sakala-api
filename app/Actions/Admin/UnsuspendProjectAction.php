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
use App\Services\Notification\UserNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lifts a suspension in the control plane only. Nothing restarts by itself: a
 * workload that was stopped stays stopped until the owner deploys again.
 */
final class UnsuspendProjectAction
{
    public function __construct(
        private readonly RecordUsageSignalAction $recordAction,
        private readonly UserNotifier $notifier,
    ) {}

    public function handle(
        Project $project,
        User $user,
        ProjectControlData $data,
    ): ProjectControlResultData {
        return DB::transaction(function () use ($project, $user, $data): ProjectControlResultData {
            /** @var Project $locked */
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);

            if ($data->idempotencyKey !== null) {
                $existing = ProjectControlRequest::query()->where('idempotency_key', $data->idempotencyKey)->first();

                if ($existing !== null) {
                    return $this->replay($existing, $locked, $user, $data);
                }

                if (AgentCommand::query()->where('idempotency_key', $data->idempotencyKey)->exists()) {
                    abort(409, 'Idempotency key ini sudah dipakai untuk permintaan lain. Pakai key baru.');
                }
            }

            if ($locked->status !== ProjectStatus::Suspended) {
                abort(409, 'Proyek ini tidak sedang ditangguhkan.');
            }

            // The command would still stop whatever the owner deploys next.
            $sleeping = AgentCommand::query()
                ->where('project_id', $locked->id)
                ->where('type', AgentCommandType::SleepProject)
                ->whereIn('status', [AgentCommandStatus::Pending, AgentCommandStatus::Claimed, AgentCommandStatus::Running])
                ->exists();

            if ($sleeping) {
                abort(409, 'Command penangguhan proyek ini masih berjalan. Tunggu sampai selesai.');
            }

            $hasSucceeded = Deployment::query()
                ->where('project_id', $locked->id)
                ->where('status', DeploymentStatus::Succeeded)
                ->exists();

            $locked->update(['status' => $hasSucceeded ? ProjectStatus::Active : ProjectStatus::Draft]);

            $responseContext = [
                'project_status' => $locked->status->value,
                'runtime_status' => $locked->runtime_status->value,
            ];

            ProjectControlRequest::create([
                'project_id' => $locked->id,
                'action' => ProjectControlAction::Unsuspend,
                'idempotency_key' => $data->idempotencyKey ?? Str::uuid()->toString(),
                'actor_type' => User::class,
                'actor_id' => $user->id,
                'reason' => $data->reason,
                'response_context' => $responseContext,
            ]);

            AuditEvent::create([
                'actor_type' => User::class,
                'actor_id' => $user->id,
                'action' => 'project.unsuspended',
                'subject_type' => Project::class,
                'subject_id' => $locked->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'metadata' => ['reason' => $data->reason],
            ]);

            $this->recordAction->handle(
                type: UsageSignalType::ManualIntervention,
                count: 1,
                scope: 'project',
                scopeId: (string) $locked->id,
                tags: ['action_code' => 'manual_unsuspend'],
            );

            $this->notifier->send(
                $locked->user,
                NotificationCategory::ProjectIssue,
                'Proyek dibuka kembali',
                "{$locked->name} sudah tidak ditangguhkan. Deploy ulang untuk menjalankannya lagi.",
                $locked->id,
                mandatory: true,
            );

            return new ProjectControlResultData(
                project: $locked->refresh(),
                command: null,
                responseContext: $responseContext,
            );
        });
    }

    private function replay(
        ProjectControlRequest $existing,
        Project $project,
        User $user,
        ProjectControlData $data,
    ): ProjectControlResultData {
        if (
            $existing->project_id !== $project->id
            || $existing->action !== ProjectControlAction::Unsuspend
            || $existing->reason !== $data->reason
            || $existing->actor_type !== User::class
            || (string) $existing->actor_id !== (string) $user->id
        ) {
            abort(409, 'Idempotency key ini sudah dipakai untuk permintaan lain. Pakai key baru.');
        }

        /** @var array<string, mixed> $responseContext */
        $responseContext = $existing->response_context ?? [];

        return new ProjectControlResultData(
            project: $project->refresh(),
            command: null,
            responseContext: $responseContext,
        );
    }
}
