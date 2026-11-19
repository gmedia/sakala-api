<?php

declare(strict_types=1);

use App\Rules\NetworkHost;

test('hosts without a port, scheme, or path are accepted', function (string $host): void {
    expect(NetworkHost::isValid($host))->toBeTrue();
})->with([
    'IPv4' => ['10.0.47.30'],
    'IPv6' => ['fd00::30'],
    'loopback IPv6' => ['::1'],
    'name' => ['db-01.internal'],
    'single label' => ['postgres'],
]);

test('anything else is refused', function (mixed $host): void {
    expect(NetworkHost::isValid($host))->toBeFalse();
})->with([
    'empty' => [''],
    'with a port' => ['10.0.47.30:5432'],
    'bracketed IPv6' => ['[fd00::30]'],
    'with a scheme' => ['postgres://db'],
    'with a path' => ['db/x'],
    'bad dotted quad' => ['10.0.0.300'],
    'leading hyphen' => ['-db.internal'],
    'too long' => [str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 63)],
    'not a string' => [5432],
    'null' => [null],
]);
