<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScheduleHealth;
use App\Enums\SchedulePauseReason;
use App\Enums\ScheduleRunStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A scheduled job of a project (ADR-022): `command` runs once per slot of
 * `cron_expression`, read in `timezone`, in a one-off container of the live
 * image (RunJob). `schedules:dispatch` is the only scheduler.
 *
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property string $command
 * @property string $cron_expression Standard five fields: minute, hour, day of month, month, day of week.
 * @property string $timezone
 * @property int $timeout_seconds
 * @property bool $enabled
 * @property CarbonImmutable|null $next_run_at The next slot the dispatcher has not handled, in UTC; null while disabled.
 * @property ScheduleHealth $health
 * @property int $consecutive_failures
 * @property CarbonImmutable|null $paused_at When the control plane disabled it by itself.
 * @property SchedulePauseReason|null $pause_reason
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'project_id',
    'name',
    'command',
    'cron_expression',
    'timezone',
    'timeout_seconds',
    'enabled',
    'next_run_at',
    'health',
    'consecutive_failures',
    'paused_at',
    'pause_reason',
    'created_by',
])]
class ProjectSchedule extends Model
{
    /** @use HasFactory<ProjectScheduleFactory> */
    use HasFactory, HasUuids;

    /** Longest name, in characters. */
    public const MAX_NAME_LENGTH = 100;

    /** @var array<string, mixed> */
    protected $attributes = [
        'health' => ScheduleHealth::Healthy->value,
        'consecutive_failures' => 0,
    ];

    /**
     * Enabled schedules of the workspace's live projects, what counts towards
     * `max_schedules`. A disabled or paused schedule holds no slot.
     */
    public static function enabledCountIn(string $workspaceId): int
    {
        return self::query()
            ->where('enabled', true)
            ->whereIn('project_id', Project::query()->select('id')->where('workspace_id', $workspaceId))
            ->count();
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<ScheduleRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(ScheduleRun::class, 'schedule_id');
    }

    /**
     * The newest run, whatever its outcome. The limit applies per schedule
     * when eager loaded, as on Project::latestDeployment().
     *
     * @return HasOne<ScheduleRun, $this>
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(ScheduleRun::class, 'schedule_id')->orderByDesc('created_at')->limit(1);
    }

    /** The run that is waiting, queued, or running; a schedule has at most one. */
    public function openRun(): ?ScheduleRun
    {
        return $this->runs()->whereIn('status', ScheduleRunStatus::openCases())->first();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'timeout_seconds' => 'integer',
            'enabled' => 'boolean',
            'next_run_at' => 'immutable_datetime',
            'health' => ScheduleHealth::class,
            'consecutive_failures' => 'integer',
            'paused_at' => 'immutable_datetime',
            'pause_reason' => SchedulePauseReason::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
