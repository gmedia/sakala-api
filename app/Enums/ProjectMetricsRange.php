<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ranges of GET app/projects/{project}/metrics, each with its step and source
 * table. The step keeps every range under about 200 points.
 */
enum ProjectMetricsRange: string
{
    case LastHour = '1h';
    case Last6Hours = '6h';
    case Last24Hours = '24h';
    case Last7Days = '7d';
    case Last30Days = '30d';

    public const DEFAULT = self::LastHour;

    private const MINUTE = 60;

    private const HOUR = 3600;

    private const DAY = 86400;

    public function seconds(): int
    {
        return match ($this) {
            self::LastHour => self::HOUR,
            self::Last6Hours => 6 * self::HOUR,
            self::Last24Hours => self::DAY,
            self::Last7Days => 7 * self::DAY,
            self::Last30Days => 30 * self::DAY,
        };
    }

    public function stepSeconds(): int
    {
        return match ($this) {
            self::LastHour => self::MINUTE,
            self::Last6Hours => 5 * self::MINUTE,
            self::Last24Hours => 15 * self::MINUTE,
            self::Last7Days => self::HOUR,
            self::Last30Days => 4 * self::HOUR,
        };
    }

    /** 7d and 30d read the hourly roll-ups; shorter ranges read minute rows. */
    public function readsHourlyRollups(): bool
    {
        return $this->seconds() > self::DAY;
    }
}
