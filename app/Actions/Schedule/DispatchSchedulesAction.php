<?php

declare(strict_types=1);

namespace App\Actions\Schedule;

use App\Enums\ScheduleRunReason;
use App\Enums\ScheduleRunStatus;
use App\Enums\ScheduleRunTrigger;
use App\Models\ProjectSchedule;
use App\Models\ScheduleRun;
use App\Services\Schedule\SchedulePolicy;
use App\Services\Schedule\ScheduleRunLauncher;
use App\Services\Schedule\ScheduleRunOutcomeService;
use App\Support\Runtime\RuntimeFeatures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only scheduler of ADR-022, run every minute by `schedules:dispatch` on
 * one server. Two passes, each schedule in its own transaction under the
 * workspace and schedule row locks:
 *
 * 1. Waiting runs: started once the workspace has a free slot, missed
 *    (`concurrency_limit`) once their start deadline passed.
 * 2. Due schedules (`next_run_at` reached): the latest slot at or before now
 *    becomes a run, unique per schedule and slot, through
 *    ScheduleRunLauncher; a slot past its start deadline is recorded as
 *    `missed` (`dispatcher_late`) and never run late. When the dispatcher was
 *    away for several slots, the first one it missed and the latest are
 *    recorded, not every slot in between, so an outage never writes a burst
 *    of rows. `next_run_at` then moves past now.
 *
 * Does nothing while RuntimeFeatures::schedulesEnabled() is false.
 */
final class DispatchSchedulesAction
{
    public function __construct(
        private readonly ScheduleRunLauncher $launcher,
        private readonly SchedulePolicy $policy,
        private readonly ScheduleRunOutcomeService $outcomes,
    ) {}

    /**
     * @return array{started: int, waiting: int, skipped: int, missed: int}
     */
    public function handle(int $limit = 200): array
    {
        $counts = ['started' => 0, 'waiting' => 0, 'skipped' => 0, 'missed' => 0];

        if (! RuntimeFeatures::schedulesEnabled()) {
            return $counts;
        }

        $waiting = ScheduleRun::query()
            ->where('status', ScheduleRunStatus::Waiting)
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($waiting as $runId) {
            $this->count($counts, $this->retryWaiting($runId));
        }

        $due = ProjectSchedule::query()
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', CarbonImmutable::now())
            ->orderBy('next_run_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($due as $scheduleId) {
            foreach ($this->dispatch($scheduleId) as $status) {
                $this->count($counts, $status);
            }
        }

        return $counts;
    }

    private function retryWaiting(string $runId): ?ScheduleRunStatus
    {
        return DB::transaction(function () use ($runId): ?ScheduleRunStatus {
            $run = ScheduleRun::query()->find($runId);
            $schedule = $run === null ? null : ProjectSchedule::query()->find($run->schedule_id);

            if ($run === null || $schedule === null) {
                return null;
            }

            $workspace = $this->launcher->lockWorkspace($schedule);
            $locked = $this->launcher->lockSchedule($schedule->id);
            /** @var ScheduleRun|null $run */
            $run = ScheduleRun::query()->whereKey($runId)->lockForUpdate()->first();

            if ($locked === null || $run === null || $run->status !== ScheduleRunStatus::Waiting) {
                return null;
            }

            if ($run->deadline_at !== null && CarbonImmutable::now()->greaterThanOrEqualTo($run->deadline_at)) {
                $this->launcher->close($run, ScheduleRunStatus::Missed, ScheduleRunReason::ConcurrencyLimit);
                $this->outcomes->pruneRuns($locked);

                return ScheduleRunStatus::Missed;
            }

            $run = $this->launcher->launch($locked, $run, $workspace);

            return $run->status === ScheduleRunStatus::Waiting ? null : $run->status;
        });
    }

    /** @return list<ScheduleRunStatus> */
    private function dispatch(string $scheduleId): array
    {
        return DB::transaction(function () use ($scheduleId): array {
            $schedule = ProjectSchedule::query()->find($scheduleId);

            if ($schedule === null) {
                return [];
            }

            $workspace = $this->launcher->lockWorkspace($schedule);
            $locked = $this->launcher->lockSchedule($scheduleId);
            $now = CarbonImmutable::now();

            // Re-read under the lock: a change or another dispatcher may have
            // moved the slot since the scan.
            if ($locked === null || ! $locked->enabled || $locked->next_run_at === null || $locked->next_run_at->greaterThan($now)) {
                return [];
            }

            $expression = $locked->cron_expression;
            $timezone = $locked->timezone;
            $due = $locked->next_run_at;
            $latest = $this->policy->latestSlotAtOrBefore($expression, $timezone, $now);
            $statuses = [];

            if ($latest->greaterThan($due)) {
                // Away for at least one slot: the first one missed is recorded.
                $statuses[] = $this->missed($locked, $due);
                $slot = $latest;
            } else {
                $slot = $due;
            }

            $deadline = $this->policy->deadlineFor($expression, $timezone, $slot);

            if ($now->greaterThanOrEqualTo($deadline)) {
                $statuses[] = $this->missed($locked, $slot);
            } else {
                $run = $this->launcher->launch(
                    $locked,
                    $this->launcher->newRun($locked, ScheduleRunTrigger::Scheduled, $slot, $deadline),
                    $workspace,
                );
                $statuses[] = $run->status;
            }

            $locked->update(['next_run_at' => $this->policy->nextSlotAfter($expression, $timezone, $now->max($slot))]);
            $this->outcomes->pruneRuns($locked);

            return $statuses;
        });
    }

    private function missed(ProjectSchedule $schedule, CarbonImmutable $slot): ScheduleRunStatus
    {
        $run = $this->launcher->newRun($schedule, ScheduleRunTrigger::Scheduled, $slot, $this->policy->deadlineFor($schedule->cron_expression, $schedule->timezone, $slot));
        $this->launcher->close($run, ScheduleRunStatus::Missed, ScheduleRunReason::DispatcherLate);

        return ScheduleRunStatus::Missed;
    }

    /** @param  array{started: int, waiting: int, skipped: int, missed: int}  $counts */
    private function count(array &$counts, ?ScheduleRunStatus $status): void
    {
        $key = match ($status) {
            ScheduleRunStatus::Queued => 'started',
            ScheduleRunStatus::Waiting => 'waiting',
            ScheduleRunStatus::Skipped => 'skipped',
            ScheduleRunStatus::Missed => 'missed',
            default => null,
        };

        if ($key !== null) {
            $counts[$key]++;
        }
    }
}
