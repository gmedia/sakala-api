<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DateTimeInterface;
use DateTimeZone;

/**
 * The rules of a schedule's time (ADR-022): standard five-field cron
 * expressions read in the schedule's timezone, slots stored in UTC, the start
 * deadline after which a slot counts as missed, and the minimum interval. The
 * only clock is the API host's; the node only measures durations.
 */
final class SchedulePolicy
{
    /** Minutes after its time a slot may still start; the interval wins when shorter. */
    public const DEFAULT_START_DEADLINE_MINUTES = 10;

    /** Finished runs kept per schedule. */
    public const DEFAULT_RUNS_KEPT = 100;

    /** Failed, timed out, or lost runs in a row before the schedule is paused. */
    public const DEFAULT_PAUSE_AFTER_FAILURES = 10;

    /** Timezone of a schedule created without one: WIB, which has no daylight saving. */
    public const DEFAULT_TIMEZONE = 'Asia/Jakarta';

    /** Timeout of a run when the schedule sets none, as the agent's own default. */
    public const DEFAULT_TIMEOUT_SECONDS = 300;

    /** The longest timeout a run may ask for, the release command's node deadline. */
    public const MAX_TIMEOUT_SECONDS = 600;

    /** Fields of a standard cron expression: minute, hour, day of month, month, day of week. */
    public const CRON_FIELDS = 5;

    /** Upcoming slots compared to enforce the minimum interval. */
    public const INTERVAL_CHECK_SLOTS = 1000;

    private const SECONDS_PER_MINUTE = 60;

    /** Whether `$expression` is a standard five-field cron expression; macros such as `@daily` are not. */
    public static function isValidExpression(string $expression): bool
    {
        $fields = preg_split('/\s+/', trim($expression)) ?: [];

        return count($fields) === self::CRON_FIELDS && CronExpression::isValidExpression(trim($expression));
    }

    /** Whether `$timezone` is a timezone identifier PHP knows, such as `Asia/Jakarta`. */
    public static function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * The smallest gap, in whole minutes, between two of the next
     * INTERVAL_CHECK_SLOTS slots after `$from`. An expression is refused when
     * it is below the workspace's minimum interval.
     */
    public function shortestGapMinutes(string $expression, string $timezone, ?DateTimeInterface $from = null): int
    {
        $dates = $this->cron($expression)->getMultipleRunDates(
            self::INTERVAL_CHECK_SLOTS,
            $from ?? CarbonImmutable::now(),
            timeZone: $timezone,
        );

        $shortest = PHP_INT_MAX;

        for ($index = 1, $count = count($dates); $index < $count; $index++) {
            $gap = intdiv($dates[$index]->getTimestamp() - $dates[$index - 1]->getTimestamp(), self::SECONDS_PER_MINUTE);
            $shortest = min($shortest, $gap);
        }

        return $shortest;
    }

    /** The first slot strictly after `$after`, in UTC. */
    public function nextSlotAfter(string $expression, string $timezone, DateTimeInterface $after): CarbonImmutable
    {
        return $this->utc($this->cron($expression)->getNextRunDate($after, 0, false, $timezone));
    }

    /** The latest slot at or before `$at`, in UTC. */
    public function latestSlotAtOrBefore(string $expression, string $timezone, DateTimeInterface $at): CarbonImmutable
    {
        return $this->utc($this->cron($expression)->getPreviousRunDate($at, 0, true, $timezone));
    }

    /**
     * When `$slot` stops being allowed to start: the start deadline, or the
     * next slot when that comes sooner, so a run never starts after the next
     * one is due.
     */
    public function deadlineFor(string $expression, string $timezone, CarbonImmutable $slot): CarbonImmutable
    {
        $deadline = $slot->addMinutes($this->startDeadlineMinutes());
        $next = $this->nextSlotAfter($expression, $timezone, $slot);

        return $next->lessThan($deadline) ? $next : $deadline;
    }

    /** The start deadline of a manual run, requested at `$at`. */
    public function manualDeadline(CarbonImmutable $at): CarbonImmutable
    {
        return $at->addMinutes($this->startDeadlineMinutes());
    }

    public function startDeadlineMinutes(): int
    {
        return max(1, (int) config('sakala.schedules.start_deadline_minutes', self::DEFAULT_START_DEADLINE_MINUTES));
    }

    public function runsKept(): int
    {
        return max(1, (int) config('sakala.schedules.runs_kept', self::DEFAULT_RUNS_KEPT));
    }

    public function pauseAfterFailures(): int
    {
        return max(1, (int) config('sakala.schedules.pause_after_failures', self::DEFAULT_PAUSE_AFTER_FAILURES));
    }

    private function cron(string $expression): CronExpression
    {
        return new CronExpression(trim($expression));
    }

    private function utc(DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC($date->getTimestamp());
    }
}
