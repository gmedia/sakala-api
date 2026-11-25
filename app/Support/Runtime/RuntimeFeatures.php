<?php

declare(strict_types=1);

namespace App\Support\Runtime;

/**
 * Feature flags of ADR-022 and ADR-023, off by default so an installation
 * changes nothing until an operator turns them on (`SAKALA_WORKERS_ENABLED`,
 * `SAKALA_SCHEDULES_ENABLED`, `SAKALA_VALKEY_ENABLED`).
 */
final class RuntimeFeatures
{
    /** Route middleware parameter of EnsureRuntimeFeatureEnabled for worker processes. */
    public const WORKERS = 'workers';

    /** Route middleware parameter of EnsureRuntimeFeatureEnabled for scheduled jobs. */
    public const SCHEDULES = 'schedules';

    /** Route middleware parameter of EnsureRuntimeFeatureEnabled for managed Valkey (ADR-023). */
    public const VALKEY = 'valkey';

    public static function workersEnabled(): bool
    {
        return config('sakala.workers.enabled') === true;
    }

    public static function schedulesEnabled(): bool
    {
        return config('sakala.schedules.enabled') === true;
    }

    public static function valkeyEnabled(): bool
    {
        return config('sakala.valkey.enabled') === true;
    }

    public static function enabled(string $feature): bool
    {
        return match ($feature) {
            self::WORKERS => self::workersEnabled(),
            self::SCHEDULES => self::schedulesEnabled(),
            self::VALKEY => self::valkeyEnabled(),
            default => false,
        };
    }
}
