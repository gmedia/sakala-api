<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of one run of a schedule (ADR-022). A slot is recorded once, as
 * a run in one of these states, and never run late in a burst.
 */
enum ScheduleRunStatus: string
{
    /** The workspace already runs `max_concurrent_job_runs` jobs; the run starts when one ends, or is missed at its deadline. */
    case Waiting = 'waiting';

    /** A RunJob command waits for the node to claim it before the start deadline. */
    case Queued = 'queued';

    /** The node claimed the RunJob command. */
    case Running = 'running';

    /** The command exited with code 0. */
    case Succeeded = 'succeeded';

    /** The command exited with another code, or the node could not run it. */
    case Failed = 'failed';

    /** The command ran past the schedule's timeout and was stopped. */
    case TimedOut = 'timed_out';

    /** Stopped on request (Stop run), by a StopProject, or because the project was deleted. */
    case Cancelled = 'cancelled';

    /** The node went away during the run: its lease expired without a report. */
    case Lost = 'lost';

    /** The slot did not start before its deadline (`reason` says why). */
    case Missed = 'missed';

    /** The slot was not run on purpose (`reason` says why). */
    case Skipped = 'skipped';

    /**
     * Runs that may still start or are running: a schedule has at most one,
     * and a workspace's count against `max_concurrent_job_runs` is of the
     * queued and running ones.
     *
     * @return list<self>
     */
    public static function openCases(): array
    {
        return [self::Waiting, self::Queued, self::Running];
    }

    /**
     * Runs that hold one of the workspace's concurrent job slots.
     *
     * @return list<self>
     */
    public static function activeCases(): array
    {
        return [self::Queued, self::Running];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::openCases(), true);
    }

    /**
     * Ends that count towards a failing schedule and its pause after
     * `pause_after_failures` in a row. A missed or skipped slot did not run
     * the job, and a cancelled one was stopped on purpose.
     */
    public function isFailure(): bool
    {
        return in_array($this, [self::Failed, self::TimedOut, self::Lost], true);
    }
}
