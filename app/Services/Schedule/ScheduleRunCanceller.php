<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Enums\AgentCommandStatus;
use App\Enums\ScheduleRunStatus;
use App\Models\AgentCommand;
use App\Models\ProjectSchedule;
use App\Models\ScheduleRun;

/**
 * Stops an open run: a waiting run, or one whose RunJob nobody claimed yet,
 * ends at once as `cancelled`; a claimed one gets `cancel_requested_at`, the
 * node stops its container (`cancel_requested` in the poll), and its
 * `runtime_cancelled` report ends the run. The caller holds a transaction.
 */
final class ScheduleRunCanceller
{
    /** `error_code` of a RunJob closed before a node claimed it. */
    public const CANCELLED_BEFORE_CLAIM = 'schedule_run_cancelled';

    /** Returns false when the run already ended. */
    public function cancel(ScheduleRun $run): bool
    {
        /** @var ScheduleRun $locked */
        $locked = ScheduleRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

        if (! $locked->status->isOpen()) {
            return false;
        }

        if ($locked->agent_command_id !== null) {
            $closed = AgentCommand::query()
                ->whereKey($locked->agent_command_id)
                ->where('status', AgentCommandStatus::Pending->value)
                ->update([
                    'status' => AgentCommandStatus::Cancelled->value,
                    'error_code' => self::CANCELLED_BEFORE_CLAIM,
                    'error_message' => 'The schedule run was cancelled before a node claimed it.',
                    'updated_at' => now(),
                ]);

            if ($closed === 0) {
                AgentCommand::query()
                    ->whereKey($locked->agent_command_id)
                    ->whereIn('status', [AgentCommandStatus::Claimed->value, AgentCommandStatus::Running->value])
                    ->whereNull('cancel_requested_at')
                    ->update(['cancel_requested_at' => now(), 'updated_at' => now()]);

                return true;
            }
        }

        $locked->update(['status' => ScheduleRunStatus::Cancelled, 'finished_at' => now()]);

        return true;
    }

    /** Stop every open run of `$schedule`, before it or its project is deleted. */
    public function cancelOpenRuns(ProjectSchedule $schedule): void
    {
        $schedule->runs()
            ->whereIn('status', ScheduleRunStatus::openCases())
            ->get()
            ->each(fn (ScheduleRun $run): bool => $this->cancel($run));
    }
}
