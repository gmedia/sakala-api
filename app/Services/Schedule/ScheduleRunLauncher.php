<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\ProjectStatus;
use App\Enums\ScheduleRunReason;
use App\Enums\ScheduleRunStatus;
use App\Enums\ScheduleRunTrigger;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleRun;
use App\Models\Workspace;
use App\Services\Runtime\PilotRuntimeLimitService;
use App\Services\Runtime\ProjectWorkloadService;
use Carbon\CarbonImmutable;

/**
 * Decides what a due run becomes and creates its RunJob command (ADR-022).
 * Shared by `schedules:dispatch` and "Run now", so both follow the same rules
 * in the same order: a deleted or suspended project, an open run of the same
 * schedule, no live deployment, and a node without `scheduled-jobs` skip the
 * run; a workspace at `max_concurrent_job_runs` makes it wait; otherwise it
 * is queued.
 *
 * Callers hold, in this order, the workspace row lock (lockWorkspace()) and
 * the schedule row lock, so the concurrency count and the open run cannot
 * change under them. A run never wakes the project: it only needs the image,
 * which a sleeping project keeps.
 */
final class ScheduleRunLauncher
{
    /** Seconds the agent adds to a job's timeout to stop, remove, and report its container. */
    public const AGENT_JOB_STOP_GRACE_SECONDS = 30;

    /** Prefix of a RunJob's idempotency key; one per slot, or per manual run. */
    private const IDEMPOTENCY_PREFIX = 'schedule-run';

    public function __construct(
        private readonly ProjectWorkloadService $workloads,
        private readonly PilotRuntimeLimitService $limits,
    ) {}

    /** Lock the workspace of the schedule's project, the first lock of every launch. */
    public function lockWorkspace(ProjectSchedule $schedule): ?Workspace
    {
        $workspaceId = Project::withTrashed()->whereKey($schedule->project_id)->value('workspace_id');

        if ($workspaceId === null) {
            return null;
        }

        /** @var Workspace|null */
        return Workspace::query()->whereKey($workspaceId)->lockForUpdate()->first();
    }

    /** Lock the schedule row, after the workspace. */
    public function lockSchedule(string $scheduleId): ?ProjectSchedule
    {
        /** @var ProjectSchedule|null */
        return ProjectSchedule::query()->whereKey($scheduleId)->lockForUpdate()->first();
    }

    /**
     * Why the run cannot start at all, or null when it may. `$exceptRunId` is
     * a waiting run being retried, which is not its own overlap.
     */
    public function blockingReason(ProjectSchedule $schedule, ?string $exceptRunId = null): ?ScheduleRunReason
    {
        $project = Project::withTrashed()->find($schedule->project_id);

        if ($project === null || $project->trashed()) {
            return ScheduleRunReason::ProjectDeleted;
        }

        if ($project->status === ProjectStatus::Suspended) {
            return ScheduleRunReason::ProjectSuspended;
        }

        $open = $schedule->runs()
            ->whereIn('status', ScheduleRunStatus::openCases())
            ->when($exceptRunId !== null, fn ($query) => $query->whereKeyNot($exceptRunId))
            ->exists();

        if ($open) {
            return ScheduleRunReason::Overlap;
        }

        $deployment = $this->liveDeployment($project);

        if ($deployment === null || $this->deployPayload($deployment) === null) {
            return ScheduleRunReason::NoLiveDeployment;
        }

        $node = AgentNode::query()->find($deployment->agent_node_id);

        if ($node === null || ! $node->hasCapability(AgentCommandType::SCHEDULED_JOBS_CAPABILITY)) {
            return ScheduleRunReason::NodeUnsupported;
        }

        return null;
    }

    /** Whether the workspace runs fewer jobs than its `max_concurrent_job_runs`. */
    public function hasFreeSlot(?Workspace $workspace): bool
    {
        if ($workspace === null) {
            return false;
        }

        $max = $this->limits->limitsFor($workspace)->max_concurrent_job_runs;

        return ScheduleRun::query()->activeInWorkspace($workspace->id)->count() < $max;
    }

    /**
     * A new run of `$schedule`, not saved yet.
     */
    public function newRun(ProjectSchedule $schedule, ScheduleRunTrigger $trigger, ?CarbonImmutable $slot, CarbonImmutable $deadline, ?int $requestedBy = null): ScheduleRun
    {
        return new ScheduleRun([
            'schedule_id' => $schedule->id,
            'project_id' => $schedule->project_id,
            'trigger' => $trigger,
            'scheduled_for' => $slot,
            'command' => $schedule->command,
            'timeout_seconds' => $schedule->timeout_seconds,
            'deadline_at' => $deadline,
            'requested_by' => $requestedBy,
        ]);
    }

    /**
     * Decide and save `$run` (new, or waiting and retried): skipped with the
     * blocking reason, waiting while the workspace has no free slot, or
     * queued with its RunJob command.
     */
    public function launch(ProjectSchedule $schedule, ScheduleRun $run, ?Workspace $workspace): ScheduleRun
    {
        $reason = $this->blockingReason($schedule, $run->exists ? $run->id : null);

        if ($reason !== null) {
            return $this->close($run, ScheduleRunStatus::Skipped, $reason);
        }

        if (! $this->hasFreeSlot($workspace)) {
            $run->fill(['status' => ScheduleRunStatus::Waiting, 'reason' => ScheduleRunReason::ConcurrencyLimit])->save();

            return $run;
        }

        return $this->queue($run);
    }

    /** Close a run that will never start. */
    public function close(ScheduleRun $run, ScheduleRunStatus $status, ScheduleRunReason $reason): ScheduleRun
    {
        $run->fill([
            'status' => $status,
            'reason' => $reason,
            'finished_at' => now(),
        ])->save();

        return $run;
    }

    /** The project's live deployment: the active one, on a node, whether it runs, crashed, or sleeps. */
    public function liveDeployment(Project $project): ?Deployment
    {
        return $this->workloads->activeWorkload($project);
    }

    /**
     * Create the RunJob command, pinned to the live deployment's node, with
     * what that deployment's DeployProject payload carried: the image
     * identity, variables (still encrypted), port, resources, and log bounds.
     */
    private function queue(ScheduleRun $run): ScheduleRun
    {
        /** @var Project $project */
        $project = Project::query()->findOrFail($run->project_id);
        /** @var Deployment $deployment */
        $deployment = $this->liveDeployment($project);
        /** @var array<string, mixed> $deployPayload */
        $deployPayload = $this->deployPayload($deployment);

        // Saved first, so the command can name its run.
        $run->fill(['status' => ScheduleRunStatus::Queued, 'reason' => null, 'deployment_id' => $deployment->id])->save();

        $builder = $deployment->imageBuilderId();

        $command = AgentCommand::query()->create([
            'project_id' => $project->id,
            'deployment_id' => $deployment->id,
            'agent_node_id' => $deployment->agent_node_id,
            'type' => AgentCommandType::RunJob,
            'status' => AgentCommandStatus::Pending,
            'payload' => [
                'command' => $run->command,
                'timeout_seconds' => $run->timeout_seconds,
                'commit_sha' => $deployment->commit_sha,
                ...($builder !== null && $builder !== $deployment->id ? ['reuse_image_from_deployment_id' => $builder] : []),
                'container_port' => $deployment->container_port ?? $deployPayload['container_port'] ?? Project::DEFAULT_CONTAINER_PORT,
                'environment' => $deployPayload['environment'] ?? [],
                'resources' => $deployPayload['resources'] ?? [],
                'log_bounds' => $deployPayload['log_bounds'] ?? [],
            ],
            'request_context' => [
                'schedule_id' => $run->schedule_id,
                'schedule_run_id' => $run->id,
                'trigger' => $run->trigger->value,
            ],
            'idempotency_key' => $this->idempotencyKey($run),
            'available_at' => now(),
            // Not claimed by the start deadline: the run is missed.
            'expires_at' => $run->deadline_at,
        ]);

        $run->fill([
            'status' => ScheduleRunStatus::Queued,
            'reason' => null,
            'agent_command_id' => $command->id,
            'deployment_id' => $deployment->id,
        ])->save();

        return $run;
    }

    /**
     * The live deployment's DeployProject payload, or null when it has none
     * the job could copy (a row from before commands, or without a commit).
     *
     * @return array<string, mixed>|null
     */
    private function deployPayload(Deployment $deployment): ?array
    {
        if ($deployment->commit_sha === null) {
            return null;
        }

        $payload = AgentCommand::query()
            ->where('deployment_id', $deployment->id)
            ->where('type', AgentCommandType::DeployProject)
            ->orderBy('created_at')
            ->value('payload');

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        return is_array($payload) ? $payload : null;
    }

    /** `schedule-run:{schedule}:{slot}` for a slot, `schedule-run:{schedule}:manual:{run}` for "Run now". */
    private function idempotencyKey(ScheduleRun $run): string
    {
        $slot = $run->scheduled_for === null
            ? 'manual:'.$run->id
            : $run->scheduled_for->utc()->format('Y-m-d\TH:i:s\Z');

        return implode(':', [self::IDEMPOTENCY_PREFIX, $run->schedule_id, $slot]);
    }
}
