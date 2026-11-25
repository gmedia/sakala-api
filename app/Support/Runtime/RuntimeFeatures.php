<?php

declare(strict_types=1);

namespace App\Support\Runtime;

use App\Services\CustomDomain\CustomDomainPolicy;

/**
 * Feature flags of ADR-022, ADR-023, and ADR-024, off by default so an
 * installation changes nothing until an operator turns them on
 * (`SAKALA_WORKERS_ENABLED`, `SAKALA_SCHEDULES_ENABLED`,
 * `SAKALA_VALKEY_ENABLED`, `SAKALA_CUSTOM_DOMAINS_ENABLED`).
 */
final class RuntimeFeatures
{
    /** Route middleware parameter of EnsureRuntimeFeatureEnabled for worker processes. */
    public const WORKERS = 'workers';

    /** Route middleware parameter of EnsureRuntimeFeatureEnabled for scheduled jobs. */
    public const SCHEDULES = 'schedules';

    /** Route middleware parameter of EnsureRuntimeFeatureEnabled for managed Valkey (ADR-023). */
    public const VALKEY = 'valkey';

    /** Route middleware parameter of EnsureRuntimeFeatureEnabled for custom domains (ADR-024). */
    public const CUSTOM_DOMAINS = 'custom-domains';

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

    public static function customDomainsEnabled(): bool
    {
        return CustomDomainPolicy::enabled();
    }

    public static function enabled(string $feature): bool
    {
        return match ($feature) {
            self::WORKERS => self::workersEnabled(),
            self::SCHEDULES => self::schedulesEnabled(),
            self::VALKEY => self::valkeyEnabled(),
            self::CUSTOM_DOMAINS => self::customDomainsEnabled(),
            default => false,
        };
    }
}
