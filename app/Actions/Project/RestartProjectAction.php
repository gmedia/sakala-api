<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Admin\ProjectControlResultData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\ProjectControlAction;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Exceptions\Runtime\ProjectNotRunningException;
use App\Exceptions\Runtime\ProjectRuntimeBusyException;
use App\Exceptions\Runtime\ProjectSuspendedException;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectControlRequest;
use App\Models\User;
use App\Services\Runtime\ProjectWorkloadService;
use App\Support\Database\TransactionRetry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A member restarts the live containers of a project: a RestartProject for the
 * active deployment, on the node that runs it. Nothing is built and no
 * deployment row is created; code and variables stay what they are. The
 * request is recorded like an admin's project control (ProjectControlRequest),
 * so a retry with the same `Idempotency-Key` returns the same command.
 *
 * Refused while the project is suspended, while it changes (a deployment is
 * queued or running, or another lifecycle command is open), and unless its
 * active deployment runs or crashed: a sleeping project wakes on its own, and
 * a stopped one needs a deployment.
 */
final class RestartProjectAction
{
    /** `request_context.reason` and `project_control_requests.reason` of a member's restart. */
    public const REASON = 'user_restart';

    /** `request_context.source`: asked for in the Console. */
    public const SOURCE = 'console';

    public const REQUESTED_AUDIT_ACTION = 'project.restart_requested';

    public const COMPLETED_AUDIT_ACTION = 'project.restart_completed';

    public const FAILED_AUDIT_ACTION = 'project.restart_failed';

    /** Runtime states whose containers a restart can start again. */
    private const RESTARTABLE = [RuntimeStatus::Running, RuntimeStatus::Crashed];

    public function __construct(
        private readonly ProjectWorkloadService $workloads,
    ) {}

    /**
     * @throws ProjectSuspendedException
     * @throws ProjectRuntimeBusyException
     * @throws ProjectNotRunningException
     */
    public function handle(Project $project, User $user, ?string $idempotencyKey): ProjectControlResultData
    {
        return DB::transaction(function () use ($project, $user, $idempotencyKey): ProjectControlResultData {
            /** @var Project $locked */
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);

            $existing = $idempotencyKey === null
                ? null
                : ProjectControlRequest::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $this->replay($existing, $locked, $user);
            }

            if ($locked->status === ProjectStatus::Suspended) {
                throw new ProjectSuspendedException;
            }

            if ($this->isBusy($locked)) {
                throw new ProjectRuntimeBusyException;
            }

            $deployment = in_array($locked->runtime_status, self::RESTARTABLE, true)
                ? $this->workloads->activeWorkload($locked)
                : null;

            if ($deployment === null) {
                throw new ProjectNotRunningException($locked->runtime_status->value);
            }

            return $this->queue($locked, $deployment, $user, $idempotencyKey ?? Str::uuid()->toString());
        }, TransactionRetry::ATTEMPTS);
    }

    /**
     * The project's open restart a member asked for, newest first: what
     * ProjectResource shows as `restart`.
     */
    public static function openRestart(Project $project): ?AgentCommand
    {
        return AgentCommand::query()
            ->where('project_id', $project->id)
            ->where('type', AgentCommandType::RestartProject)
            ->open()
            ->latest('created_at')
            ->first();
    }

    /** The same key again: the same request, or 409 when it named something else. */
    private function replay(ProjectControlRequest $existing, Project $locked, User $user): ProjectControlResultData
    {
        if ($existing->project_id !== $locked->id
            || $existing->action !== ProjectControlAction::Restart
            || $existing->actor_type !== User::class
            || (string) $existing->actor_id !== (string) $user->id) {
            throw new ConflictHttpException('Idempotency key ini sudah dipakai untuk permintaan lain. Pakai key baru.');
        }

        /** @var array<string, mixed> $responseContext */
        $responseContext = $existing->response_context ?? [];

        return new ProjectControlResultData(
            project: $locked,
            command: $existing->agentCommand,
            responseContext: $responseContext,
        );
    }

    /** A deployment that is queued or running, or a lifecycle command that is still open. */
    private function isBusy(Project $locked): bool
    {
        return Deployment::query()->whereBelongsTo($locked)->active()->exists()
            || AgentCommand::query()
                ->where('project_id', $locked->id)
                ->whereIn('type', AgentCommandType::projectLifecycleCases())
                ->open()
                ->exists();
    }

    private function queue(Project $locked, Deployment $deployment, User $user, string $idempotencyKey): ProjectControlResultData
    {
        $responseContext = [
            'project_status' => $locked->status->value,
            'runtime_status' => $locked->runtime_status->value,
        ];

        $actor = ['actor_type' => User::class, 'actor_id' => (string) $user->id];

        $command = AgentCommand::create([
            'project_id' => $locked->id,
            'deployment_id' => $deployment->id,
            'agent_node_id' => $deployment->agent_node_id,
            'type' => AgentCommandType::RestartProject,
            'status' => AgentCommandStatus::Pending,
            // RestartProject carries identity only (AgentCommandType::carriesPayload()).
            'payload' => [],
            'request_context' => [
                'reason' => self::REASON,
                'source' => self::SOURCE,
                ...$actor,
            ],
            'response_context' => $responseContext,
            'idempotency_key' => "project-restart:{$locked->id}:".Str::uuid()->toString(),
            'available_at' => now(),
        ]);

        ProjectControlRequest::create([
            'project_id' => $locked->id,
            'action' => ProjectControlAction::Restart,
            'idempotency_key' => $idempotencyKey,
            ...$actor,
            'reason' => self::REASON,
            'agent_command_id' => $command->id,
            'response_context' => $responseContext,
        ]);

        AuditEvent::create([
            ...$actor,
            'action' => self::REQUESTED_AUDIT_ACTION,
            'subject_type' => Project::class,
            'subject_id' => $locked->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => [
                'command_id' => $command->id,
                'deployment_id' => $deployment->id,
                'agent_node_id' => $deployment->agent_node_id,
                'runtime_status' => $locked->runtime_status->value,
            ],
        ]);

        return new ProjectControlResultData(
            project: $locked,
            command: $command,
            responseContext: $responseContext,
        );
    }
}
