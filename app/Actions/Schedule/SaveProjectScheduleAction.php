<?php

declare(strict_types=1);

namespace App\Actions\Schedule;

use App\Data\Schedule\ProjectScheduleData;
use App\Enums\ScheduleHealth;
use App\Exceptions\Schedule\ScheduleLimitExceededException;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Runtime\PilotRuntimeLimitService;
use App\Services\Schedule\SchedulePolicy;
use App\Services\Schedule\ScheduleRunCanceller;
use App\Support\Project\WorkloadAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, changes, and deletes a project's schedules (ADR-022). Under the
 * workspace row lock, so two members cannot both take the last of
 * `max_schedules`. An enabled schedule's next slot is computed from now: a
 * change never runs a slot that already passed.
 */
final class SaveProjectScheduleAction
{
    public function __construct(
        private readonly PilotRuntimeLimitService $limits,
        private readonly SchedulePolicy $policy,
        private readonly ScheduleRunCanceller $canceller,
    ) {}

    public function create(Project $project, User $actor, ProjectScheduleData $data): ProjectSchedule
    {
        return DB::transaction(function () use ($project, $actor, $data): ProjectSchedule {
            $workspace = $this->lockWorkspace($project);

            $schedule = new ProjectSchedule([
                'project_id' => $project->id,
                'timezone' => SchedulePolicy::DEFAULT_TIMEZONE,
                'timeout_seconds' => SchedulePolicy::DEFAULT_TIMEOUT_SECONDS,
                'enabled' => true,
                'created_by' => $actor->id,
                ...$data->attributes(),
            ]);

            $this->ensureInterval($workspace, $schedule);

            if ($schedule->enabled) {
                $this->ensureWithinScheduleLimit($workspace, null);
                $schedule->next_run_at = $this->nextSlot($schedule);
            }

            $schedule->save();

            WorkloadAudit::record($actor, WorkloadAudit::SCHEDULE_CREATED, $project, [
                'schedule_id' => $schedule->id,
                'name' => $schedule->name,
                'cron_expression' => $schedule->cron_expression,
                'timezone' => $schedule->timezone,
                'enabled' => $schedule->enabled,
            ]);

            return $schedule;
        });
    }

    public function update(ProjectSchedule $schedule, User $actor, ProjectScheduleData $data): ProjectSchedule
    {
        return DB::transaction(function () use ($schedule, $actor, $data): ProjectSchedule {
            /** @var Project $project */
            $project = $schedule->project;
            $workspace = $this->lockWorkspace($project);

            /** @var ProjectSchedule $locked */
            $locked = ProjectSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            $wasEnabled = $locked->enabled;
            $locked->fill($data->attributes());

            if (! $locked->isDirty()) {
                return $locked;
            }

            $timing = $locked->isDirty(['cron_expression', 'timezone']);

            if ($timing) {
                $this->ensureInterval($workspace, $locked);
            }

            if ($locked->enabled && ! $wasEnabled) {
                $this->ensureWithinScheduleLimit($workspace, $locked->id);

                // Enabling again starts over: a pause and its failures are cleared.
                $locked->fill([
                    'paused_at' => null,
                    'pause_reason' => null,
                    'consecutive_failures' => 0,
                    'health' => ScheduleHealth::Healthy,
                ]);
            }

            if (! $locked->enabled) {
                $locked->next_run_at = null;
            } elseif ($timing || ! $wasEnabled) {
                $locked->next_run_at = $this->nextSlot($locked);
            }

            $changes = $locked->getDirty();
            $before = array_intersect_key($locked->getOriginal(), $changes);
            $locked->save();

            WorkloadAudit::record($actor, WorkloadAudit::SCHEDULE_UPDATED, $project, [
                'schedule_id' => $locked->id,
                'name' => $locked->name,
                'from' => $this->auditable($before),
                'to' => $this->auditable($changes),
            ]);

            return $locked;
        });
    }

    /** Delete a schedule; its open runs are stopped first and its history goes with it. */
    public function delete(ProjectSchedule $schedule, User $actor): void
    {
        DB::transaction(function () use ($schedule, $actor): void {
            /** @var Project $project */
            $project = $schedule->project;
            $this->lockWorkspace($project);

            /** @var ProjectSchedule $locked */
            $locked = ProjectSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            $this->canceller->cancelOpenRuns($locked);
            $locked->delete();

            WorkloadAudit::record($actor, WorkloadAudit::SCHEDULE_DELETED, $project, [
                'schedule_id' => $locked->id,
                'name' => $locked->name,
            ]);
        });
    }

    private function lockWorkspace(Project $project): ?Workspace
    {
        if ($project->workspace_id === null) {
            return null;
        }

        /** @var Workspace|null */
        return Workspace::query()->whereKey($project->workspace_id)->lockForUpdate()->first();
    }

    /**
     * Two of the expression's next slots may not be closer than the
     * workspace's minimum interval.
     */
    private function ensureInterval(?Workspace $workspace, ProjectSchedule $schedule): void
    {
        $minimum = $workspace === null
            ? $this->limits->scheduleLimitsFor(null)->minScheduleIntervalMinutes
            : $this->limits->limitsFor($workspace)->min_schedule_interval_minutes;

        if ($this->policy->shortestGapMinutes($schedule->cron_expression, $schedule->timezone) < $minimum) {
            throw ValidationException::withMessages([
                'cron_expression' => ["Jadwal di workspace ini paling sering berjalan setiap {$minimum} menit. Pilih ekspresi cron dengan jarak antar-run paling sedikit {$minimum} menit."],
            ]);
        }
    }

    /** @throws ScheduleLimitExceededException */
    private function ensureWithinScheduleLimit(?Workspace $workspace, ?string $exceptScheduleId): void
    {
        if ($workspace === null) {
            return;
        }

        $max = $this->limits->limitsFor($workspace)->max_schedules;
        $current = ProjectSchedule::enabledCountIn($workspace->id)
            - ($exceptScheduleId !== null && ProjectSchedule::query()->whereKey($exceptScheduleId)->where('enabled', true)->exists() ? 1 : 0);

        if ($current >= $max) {
            throw new ScheduleLimitExceededException($max, $current);
        }
    }

    private function nextSlot(ProjectSchedule $schedule): CarbonImmutable
    {
        return $this->policy->nextSlotAfter($schedule->cron_expression, $schedule->timezone, CarbonImmutable::now());
    }

    /**
     * Audit values as plain scalars: enums by value, times as ISO strings.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function auditable(array $values): array
    {
        return array_map(static fn (mixed $value): mixed => match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => CarbonImmutable::instance($value)->toIso8601String(),
            default => $value,
        }, $values);
    }
}
