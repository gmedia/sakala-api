<?php

declare(strict_types=1);

use App\Support\Http\SessionCookieScope;

test('a session cookie never keeps a domain that covers tenant applications', function (string $domain): void {
    expect(SessionCookieScope::domain('sakala-api-session', $domain, 'run.sakala.dev'))->toBeNull();
})->with(['.sakala.dev', 'sakala.dev', '.run.sakala.dev', 'run.sakala.dev', '.SAKALA.dev']);

test('a domain beside the tenant applications is left alone', function (): void {
    expect(SessionCookieScope::domain('sakala-api-session', '.control.sakala.dev', 'run.sakala.dev'))->toBe('.control.sakala.dev')
        ->and(SessionCookieScope::domain('sakala-api-session', '.sakala.dev', 'apps.sakala-run.dev'))->toBe('.sakala.dev');
});

test('a host-prefixed cookie never has a domain, because browsers would reject it', function (): void {
    expect(SessionCookieScope::domain('__Host-sakala-api-session', '.control.sakala.dev', 'run.sakala.dev'))->toBeNull();
});

test('an empty domain stays empty', function (?string $domain): void {
    expect(SessionCookieScope::domain('sakala-api-session', $domain, 'run.sakala.dev'))->toBeNull();
})->with([null, '', ' ']);
