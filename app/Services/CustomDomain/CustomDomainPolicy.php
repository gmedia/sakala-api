<?php

declare(strict_types=1);

namespace App\Services\CustomDomain;

use App\Enums\CustomDomainStatus;
use App\Models\ProjectDomain;
use Carbon\CarbonImmutable;

/**
 * The fixed rules and the configurable timing of custom domains (ADR-024).
 * Every duration is in minutes and can be shortened through
 * `sakala.custom_domains.*` so staging does not wait 72 hours; an empty
 * value means the default here. This is the only place that decides when a
 * claim is checked next and when it is warned, disabled, or given up.
 */
final class CustomDomainPolicy
{
    /** Zones that are never a custom domain, as the agent and the Edge default them. */
    public const DEFAULT_FORBIDDEN_ZONES = 'sakala.app,sakala.dev,localhost';

    /** Seconds one DNS question may take before the check counts it as `dns_error`. */
    public const DEFAULT_DNS_TIMEOUT_SECONDS = 3;

    /** The label the ownership TXT record sits on: `_sakala.<domain>`. */
    public const TXT_LABEL = '_sakala';

    /** Prefix of the ownership TXT value: `sakala-verify=<token>`. */
    public const TXT_VALUE_PREFIX = 'sakala-verify=';

    /** The certificate authority the Edge uses; a CAA record must allow it. */
    public const CERTIFICATE_AUTHORITY = 'letsencrypt.org';

    /** Most custom domains per project, the agent's and the Edge's own limit. */
    public const MAX_PER_PROJECT = 32;

    /** Longest custom domain, in characters, as DNS allows. */
    public const MAX_HOSTNAME_LENGTH = 253;

    /** Bytes of randomness in a claim token: 128 bits. */
    public const TOKEN_BYTES = 16;

    /** CNAMEs a check follows before it gives up with `points_elsewhere`. */
    public const MAX_CNAME_HOPS = 8;

    /** A pending claim is checked every minute this long after it was added, and on "check now". */
    public const DEFAULT_PENDING_FAST_WINDOW_MINUTES = 30;

    /** Gap between checks of a pending claim within the fast window. */
    public const PENDING_FAST_INTERVAL_MINUTES = 1;

    /** Gap between checks of a pending claim after the fast window. */
    public const DEFAULT_PENDING_INTERVAL_MINUTES = 15;

    /** A pending claim that has not passed this long after it was added becomes `failed`: 72 hours. */
    public const DEFAULT_PENDING_EXPIRE_MINUTES = 72 * 60;

    /** Gap between checks of a verified domain: 6 hours. */
    public const DEFAULT_RECHECK_MINUTES = 6 * 60;

    /** Failing this long, a verified domain's people get the warning email: 24 hours. */
    public const DEFAULT_WARN_AFTER_MINUTES = 24 * 60;

    /** Failing this long, a verified domain is disabled: 72 hours. */
    public const DEFAULT_DISABLE_AFTER_MINUTES = 72 * 60;

    /** Gap between checks of a disabled domain: one day. */
    public const DEFAULT_DISABLED_RECHECK_MINUTES = 24 * 60;

    /** A disabled domain comes back on its own when DNS is fixed within this long: 30 days. */
    public const DEFAULT_REACTIVATION_WINDOW_MINUTES = 30 * 24 * 60;

    /** Whether the feature is on (`SAKALA_CUSTOM_DOMAINS_ENABLED`). */
    public static function enabled(): bool
    {
        return config('sakala.custom_domains.enabled') === true;
    }

    /** The name users point a CNAME at: `SAKALA_CUSTOM_DOMAIN_TARGET`, or edge.<project domain>. */
    public function target(): string
    {
        $configured = config('sakala.custom_domains.target');

        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(strtolower(trim($configured)), '.');
        }

        return 'edge.'.strtolower((string) config('sakala.project.default_domain'));
    }

    /**
     * Zones no custom domain may be, or be under: the configured list plus
     * the project domain, lowercase, without a trailing dot.
     *
     * @return list<string>
     */
    public function forbiddenZones(): array
    {
        $zones = array_map(
            static fn (string $zone): string => rtrim(strtolower(trim($zone)), '.'),
            explode(',', (string) config('sakala.custom_domains.forbidden_zones', self::DEFAULT_FORBIDDEN_ZONES)),
        );
        $zones[] = strtolower((string) config('sakala.project.default_domain'));

        return array_values(array_unique(array_filter($zones, static fn (string $zone): bool => $zone !== '')));
    }

    /** @return list<string> Recursive resolver addresses; empty uses /etc/resolv.conf. */
    public function resolvers(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('sakala.custom_domains.resolvers', '')),
        ), static fn (string $address): bool => $address !== ''));
    }

    public function dnsTimeoutSeconds(): float
    {
        return (float) max(1, self::positiveOr('sakala.custom_domains.dns_timeout_seconds', self::DEFAULT_DNS_TIMEOUT_SECONDS));
    }

    public function publicSuffixListPath(): string
    {
        $configured = config('sakala.custom_domains.public_suffix_list_path');

        return is_string($configured) && $configured !== ''
            ? $configured
            : resource_path('data/public_suffix_list.dat');
    }

    public function warnAfterMinutes(): int
    {
        return self::positiveOr('sakala.custom_domains.warn_after_minutes', self::DEFAULT_WARN_AFTER_MINUTES);
    }

    public function disableAfterMinutes(): int
    {
        return self::positiveOr('sakala.custom_domains.disable_after_minutes', self::DEFAULT_DISABLE_AFTER_MINUTES);
    }

    /** When a domain failing since `$failingSince` is disabled. */
    public function disableAt(CarbonImmutable $failingSince): CarbonImmutable
    {
        return $failingSince->addMinutes($this->disableAfterMinutes());
    }

    /** When a pending claim added at `$createdAt` becomes `failed`. */
    public function pendingExpiresAt(CarbonImmutable $createdAt): CarbonImmutable
    {
        return $createdAt->addMinutes(self::positiveOr('sakala.custom_domains.pending_expire_minutes', self::DEFAULT_PENDING_EXPIRE_MINUTES));
    }

    /** Until when a disabled domain comes back on its own. */
    public function reactivationEndsAt(CarbonImmutable $disabledAt): CarbonImmutable
    {
        return $disabledAt->addMinutes(self::positiveOr('sakala.custom_domains.reactivation_window_minutes', self::DEFAULT_REACTIVATION_WINDOW_MINUTES));
    }

    /**
     * When the scheduler checks the claim next, from now; null when it never
     * does on its own (failed, released, admin-disabled, or disabled past the
     * reactivation window).
     */
    public function nextCheckAt(ProjectDomain $domain): ?CarbonImmutable
    {
        $now = CarbonImmutable::now();

        return match ($domain->status) {
            CustomDomainStatus::Pending => $now->addMinutes(
                $domain->created_at !== null
                && $now->lessThan($domain->created_at->addMinutes(self::positiveOr('sakala.custom_domains.pending_fast_window_minutes', self::DEFAULT_PENDING_FAST_WINDOW_MINUTES)))
                    ? self::PENDING_FAST_INTERVAL_MINUTES
                    : self::positiveOr('sakala.custom_domains.pending_interval_minutes', self::DEFAULT_PENDING_INTERVAL_MINUTES),
            ),
            CustomDomainStatus::Verified => $now->addMinutes(self::positiveOr('sakala.custom_domains.recheck_minutes', self::DEFAULT_RECHECK_MINUTES)),
            CustomDomainStatus::Disabled => $domain->admin_disabled_at !== null
                || $domain->disabled_at === null
                || $now->greaterThanOrEqualTo($this->reactivationEndsAt($domain->disabled_at))
                    ? null
                    : $now->addMinutes(self::positiveOr('sakala.custom_domains.disabled_recheck_minutes', self::DEFAULT_DISABLED_RECHECK_MINUTES)),
            CustomDomainStatus::Failed, CustomDomainStatus::Released => null,
        };
    }

    /** An unset or empty value (`KEY=` in .env) means the default; at least 1 (minute or second). */
    private static function positiveOr(string $key, int $default): int
    {
        $value = config($key);

        return max(1, filled($value) ? (int) $value : $default);
    }
}
