<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Project\CreateWakeProjectCommandAction;
use App\Actions\Project\SleepIdleProjectsAction;
use App\Data\Admin\ProjectControlData;
use App\Data\Admin\ProjectControlResultData;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\NotificationCategory;
use App\Enums\ProjectControlAction;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Enums\UnsuspendWakeOutcome;
use App\Enums\UsageSignalType;
use App\Exceptions\Runtime\ProjectWakeLimitExceededException;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectControlRequest;
use App\Models\User;
use App\Services\Notification\UserNotifier;
use App\Services\Runtime\PilotRuntimeLimitService;
use App\Services\Runtime\ProjectWorkloadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lifts a suspension. When the suspension sleep left the container on a node
 * that can wake it, the project is woken again as the admin, within the
 * workspace's container limit; otherwise it stays stopped until the owner
 * deploys again.
 */
final class UnsuspendProjectAction
{
    /** `request_context.reason` of the WakeProject that lifting a suspension queues. */
    public const WAKE_REASON = 'unsuspended';

    /** `request_context.source` and audit `source` of that WakeProject. */
    public const WAKE_SOURCE = 'unsuspend';

    public function __construct(
        private readonly RecordUsageSignalAction $recordAction,
        private readonly UserNotifier $notifier,
        private readonly ProjectWorkloadService $workloads,
        private readonly PilotRuntimeLimitService $limits,
        private readonly CreateWakeProjectCommandAction $createWakeCommand,
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

            // The command would still stop whatever the owner deploys next. An
            // idle sleep would not: it targets the deployment it was made for.
            $sleeping = AgentCommand::query()
                ->where('project_id', $locked->id)
                ->where('type', AgentCommandType::SleepProject)
                ->open()
                ->get()
                ->contains(static fn (AgentCommand $command): bool => ! $command->isIdleSleep());

            if ($sleeping) {
                abort(409, 'Command penangguhan proyek ini masih berjalan. Tunggu sampai selesai.');
            }

            $hasSucceeded = Deployment::query()
                ->where('project_id', $locked->id)
                ->where('status', DeploymentStatus::Succeeded)
                ->exists();

            $locked->update(['status' => $hasSucceeded ? ProjectStatus::Active : ProjectStatus::Draft]);

            [$wake, $wakeCommand] = $this->wake($locked, $user);

            $responseContext = [
                'project_status' => $locked->status->value,
                'runtime_status' => $locked->runtime_status->value,
                'wake' => $wake->value,
            ];

            ProjectControlRequest::create([
                'project_id' => $locked->id,
                'action' => ProjectControlAction::Unsuspend,
                'idempotency_key' => $data->idempotencyKey ?? Str::uuid()->toString(),
                'actor_type' => User::class,
                'actor_id' => $user->id,
                'reason' => $data->reason,
                'response_context' => $responseContext,
                'agent_command_id' => $wakeCommand?->id,
            ]);

            AuditEvent::create([
                'actor_type' => User::class,
                'actor_id' => $user->id,
                'action' => 'project.unsuspended',
                'subject_type' => Project::class,
                'subject_id' => $locked->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'metadata' => [
                    'reason' => $data->reason,
                    'wake' => $wake->value,
                    'wake_command_id' => $wakeCommand?->id,
                ],
            ]);

            $this->recordAction->handle(
                type: UsageSignalType::ManualIntervention,
                count: 1,
                scope: 'project',
                scopeId: (string) $locked->id,
                tags: ['action_code' => 'manual_unsuspend'],
            );

            $this->notifier->sendToProject(
                $locked,
                NotificationCategory::ProjectIssue,
                'Proyek dibuka kembali',
                match ($wake) {
                    UnsuspendWakeOutcome::Requested => "{$locked->name} sudah tidak ditangguhkan dan sedang dinyalakan lagi.",
                    UnsuspendWakeOutcome::ContainerLimitReached => "{$locked->name} sudah tidak ditangguhkan, tetapi belum dinyalakan karena batas container yang berjalan di workspace ini sudah penuh. Hapus proyek yang tidak dipakai atau tunggu proyek lain tidur, lalu deploy ulang.",
                    default => "{$locked->name} sudah tidak ditangguhkan. Deploy ulang untuk menjalankannya lagi.",
                },
                mandatory: true,
            );

            return new ProjectControlResultData(
                project: $locked->refresh(),
                command: $wakeCommand,
                responseContext: $responseContext,
            );
        });
    }

    /**
     * Start the container the suspension sleep kept, if there is one and its
     * node can wake it. Completing the wake sets `running` and points
     * `active_deployment_id` at that deployment again.
     *
     * @return array{UnsuspendWakeOutcome, AgentCommand|null}
     */
    private function wake(Project $project, User $user): array
    {
        // A project whose suspension sleep failed still runs or sleeps as it
        // did, and a sleeping one wakes on its own.
        $deployment = $project->runtime_status === RuntimeStatus::Stopped
            ? $this->workloads->retainedWorkload($project)
            : null;

        if ($deployment === null) {
            return [UnsuspendWakeOutcome::NothingToWake, null];
        }

        if ($deployment->agentNode?->hasCapability(SleepIdleProjectsAction::CAPABILITY) !== true) {
            return [UnsuspendWakeOutcome::NodeCannotWake, null];
        }

        // A wake queued before the suspension was held back by polling and
        // is delivered now.
        $open = $this->createWakeCommand->open($project);

        if ($open !== null) {
            return [UnsuspendWakeOutcome::Requested, $open];
        }

        try {
            $this->limits->checkWakeLimit($project);
        } catch (ProjectWakeLimitExceededException) {
            return [UnsuspendWakeOutcome::ContainerLimitReached, null];
        }

        $command = $this->createWakeCommand->handle(
            deployment: $deployment,
            reason: self::WAKE_REASON,
            source: self::WAKE_SOURCE,
            user: $user,
        );

        return [UnsuspendWakeOutcome::Requested, $command];
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
            command: $existing->agentCommand,
            responseContext: $responseContext,
        );
    }
}
