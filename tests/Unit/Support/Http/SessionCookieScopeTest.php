<?php

declare(strict_types=1);

use App\Support\Http\SessionCookieScope;

test('a session cookie never keeps a domain that covers tenant applications', function (string $domain, string $tenantDomain): void {
    expect(SessionCookieScope::domain('sakala-api-session', $domain, $tenantDomain))->toBeNull();
})->with([
    'the tenant domain itself' => ['.sakala.app', 'sakala.app'],
    'without the leading dot' => ['sakala.app', 'sakala.app'],
    'in another case' => ['.SAKALA.app', 'sakala.app'],
    'the parent of staging tenants' => ['.sakala.app', 'staging.sakala.app'],
    'a self-hosted parent of its tenants' => ['.kampus.ac.id', 'apps.kampus.ac.id'],
]);

test('a domain beside the tenant applications is left alone', function (): void {
    // Hosted Sakala: tenants on their own registrable domain, apart from the API.
    expect(SessionCookieScope::domain('sakala-api-session', '.sakala.dev', 'sakala.app'))->toBe('.sakala.dev')
        ->and(SessionCookieScope::domain('sakala-api-session', '.sakala.dev', 'staging.sakala.app'))->toBe('.sakala.dev')
        ->and(SessionCookieScope::domain('sakala-api-session', '.control.kampus.ac.id', 'apps.kampus.ac.id'))->toBe('.control.kampus.ac.id');
});

test('a host-prefixed cookie never has a domain, because browsers would reject it', function (): void {
    expect(SessionCookieScope::domain('__Host-sakala-api-session', '.sakala.dev', 'sakala.app'))->toBeNull();
});

test('an empty domain stays empty', function (?string $domain): void {
    expect(SessionCookieScope::domain('sakala-api-session', $domain, 'sakala.app'))->toBeNull();
})->with([null, '', ' ']);
