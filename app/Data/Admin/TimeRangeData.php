<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\AdminTimeRange;
use Carbon\CarbonImmutable;

/**
 * A resolved admin time range: `from` inclusive, `to` exclusive, both UTC.
 * Day boundaries are midnight WIB (UTC+7, no daylight saving), the time zone
 * Sakala's admins work in, so a day in the growth series is a WIB day.
 */
final readonly class TimeRangeData
{
    public const TIMEZONE = 'Asia/Jakarta';

    /** WIB is a fixed offset, which lets SQL bucket days without time zone tables. */
    public const UTC_OFFSET_HOURS = 7;

    public function __construct(
        public AdminTimeRange $range,
        public ?CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    /**
     * Whole WIB days. A preset covers today and the `days - 1` days before it,
     * a custom range `fromDate` through `toDate`, and `all` everything up to
     * today. `to` is the following midnight, so nothing created today is cut off.
     */
    public static function resolve(AdminTimeRange $range, ?string $fromDate = null, ?string $toDate = null): self
    {
        $tomorrow = CarbonImmutable::now()->setTimezone(self::TIMEZONE)->startOfDay()->addDay();
        $days = $range->days();

        if ($days !== null) {
            return new self($range, $tomorrow->subDays($days)->utc(), $tomorrow->utc());
        }

        $from = $fromDate === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $fromDate, self::TIMEZONE);
        $to = $toDate === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $toDate, self::TIMEZONE);

        if ($range === AdminTimeRange::Custom && $from instanceof CarbonImmutable && $to instanceof CarbonImmutable) {
            return new self($range, $from->utc(), $to->addDay()->utc());
        }

        return new self(AdminTimeRange::All, null, $tomorrow->utc());
    }

    /** The window of the same length just before this one; null without a start. */
    public function previous(): ?self
    {
        if ($this->from === null) {
            return null;
        }

        $seconds = $this->to->getTimestamp() - $this->from->getTimestamp();

        return new self($this->range, $this->from->subSeconds($seconds), $this->from);
    }

    /**
     * Every WIB day the range touches, oldest first, as `Y-m-d`.
     *
     * @return list<string>
     */
    public function days(): array
    {
        if ($this->from === null) {
            return [];
        }

        $days = [];
        $end = $this->to->setTimezone(self::TIMEZONE);

        for ($day = $this->from->setTimezone(self::TIMEZONE)->startOfDay(); $day->lessThan($end); $day = $day->addDay()) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    public function cacheKey(): string
    {
        return implode(':', [$this->range->value, $this->from?->getTimestamp() ?? 'all', $this->to->getTimestamp()]);
    }
}
