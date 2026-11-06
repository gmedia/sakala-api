<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * Keeps the session cookie away from tenant applications, whatever the env says.
 *
 * Tenant applications are other people's servers on subdomains of the tenant
 * domain. A session cookie scoped to a parent of that domain reaches them, and
 * HttpOnly does nothing against a server reading its own request headers.
 */
final class SessionCookieScope
{
    /** Browsers only store a `__Host-` cookie with Secure, Path=/ and no Domain. */
    public const HOST_PREFIX = '__Host-';

    public static function isHostPrefixed(string $cookieName): bool
    {
        return str_starts_with($cookieName, self::HOST_PREFIX);
    }

    /**
     * The Domain attribute the session cookie may really use: none for a
     * `__Host-` name, which the browser would otherwise reject, and none for a
     * domain that also covers tenant applications.
     */
    public static function domain(string $cookieName, ?string $domain, string $tenantDomain): ?string
    {
        if ($domain === null || trim($domain) === '' || self::isHostPrefixed($cookieName)) {
            return null;
        }

        return self::covers($domain, $tenantDomain) ? null : $domain;
    }

    private static function covers(string $domain, string $tenantDomain): bool
    {
        $parent = strtolower(ltrim(trim($domain), '.'));
        $tenant = strtolower(ltrim(trim($tenantDomain), '.'));

        return $tenant === $parent || str_ends_with($tenant, '.'.$parent);
    }
}
