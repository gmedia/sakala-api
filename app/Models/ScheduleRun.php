<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScheduleRunReason;
use App\Enums\ScheduleRunStatus;
use App\Enums\ScheduleRunTrigger;
use Carbon\CarbonImmutable;
use Database\Factories\ScheduleRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of a schedule: a slot the dispatcher recorded, or a "Run now".
 * `command` and `timeout_seconds` are what the run was started with, so a
 * later change of the schedule does not rewrite history.
 *
 * @property string $id
 * @property string $schedule_id
 * @property string $project_id
 * @property ScheduleRunTrigger $trigger
 * @property CarbonImmutable|null $scheduled_for The slot, in UTC; null for a manual run.
 * @property ScheduleRunStatus $status
 * @property ScheduleRunReason|null $reason Why it was skipped, missed, or waited.
 * @property string|null $agent_command_id The RunJob command, once one was created.
 * @property string|null $deployment_id The live deployment whose image the run used.
 * @property string $command
 * @property int $timeout_seconds
 * @property int|null $exit_code From the agent's `job.finished` event or completion; null after a timeout or before the job ended.
 * @property int|null $duration_ms
 * @property bool|null $oom_killed
 * @property bool|null $timed_out
 * @property string|null $error_code
 * @property string|null $error_message
 * @property CarbonImmutable|null $deadline_at The run must start before this, or it is missed.
 * @property CarbonImmutable|null $started_at When the node claimed it.
 * @property CarbonImmutable|null $finished_at
 * @property int|null $requested_by The member who asked for a manual run.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'schedule_id',
    'project_id',
    'trigger',
    'scheduled_for',
    'status',
    'reason',
    'agent_command_id',
    'deployment_id',
    'command',
    'timeout_seconds',
    'exit_code',
    'duration_ms',
    'oom_killed',
    'timed_out',
    'error_code',
    'error_message',
    'deadline_at',
    'started_at',
    'finished_at',
    'requested_by',
])]
class ScheduleRun extends Model
{
    /** @use HasFactory<ScheduleRunFactory> */
    use HasFactory, HasUuids;

    /** @return BelongsTo<ProjectSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ProjectSchedule::class, 'schedule_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<AgentCommand, $this> */
    public function agentCommand(): BelongsTo
    {
        return $this->belongsTo(AgentCommand::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Scope a query to runs that hold one of the workspace's concurrent job
     * slots (queued or running), for `max_concurrent_job_runs`.
     *
     * @param  Builder<ScheduleRun>  $query
     * @return Builder<ScheduleRun>
     */
    public function scopeActiveInWorkspace(Builder $query, string $workspaceId): Builder
    {
        return $query
            ->whereIn('status', ScheduleRunStatus::activeCases())
            ->whereIn('project_id', Project::withTrashed()->select('id')->where('workspace_id', $workspaceId));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trigger' => ScheduleRunTrigger::class,
            'scheduled_for' => 'immutable_datetime',
            'status' => ScheduleRunStatus::class,
            'reason' => ScheduleRunReason::class,
            'timeout_seconds' => 'integer',
            'exit_code' => 'integer',
            'duration_ms' => 'integer',
            'oom_killed' => 'boolean',
            'timed_out' => 'boolean',
            'deadline_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
