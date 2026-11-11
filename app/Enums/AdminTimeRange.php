<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Time ranges the admin console offers, in whole WIB days: "7d" is today and
 * the six days before it.
 */
enum AdminTimeRange: string
{
    case Last7Days = '7d';
    case Last30Days = '30d';
    case Last90Days = '90d';
    case Custom = 'custom';
    case All = 'all';

    /** Days covered by a preset; null for custom and all. */
    public function days(): ?int
    {
        return match ($this) {
            self::Last7Days => 7,
            self::Last30Days => 30,
            self::Last90Days => 90,
            default => null,
        };
    }

    /** @return list<string> */
    public static function boundedValues(): array
    {
        return [self::Last7Days->value, self::Last30Days->value, self::Last90Days->value, self::Custom->value];
    }
}
