<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * The edge chain is Cloudflare -> host Caddy -> nginx -> PHP-FPM. Host Caddy
 * trusts Cloudflare and rewrites X-Forwarded-For to the single real client
 * address, so by the time a request reaches PHP the only untrusted hop left in
 * that header is the client itself.
 */
beforeEach(function (): void {
    Route::get('/test-client-ip', fn () => ['ip' => request()->ip(), 'secure' => request()->isSecure()]);
});

it('reports the forwarded client address when the request comes from an internal hop', function (): void {
    $this->withServerVariables([
        'REMOTE_ADDR' => '172.18.0.5',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->getJson('/test-client-ip')
        ->assertOk()
        ->assertJson(['ip' => '203.0.113.7', 'secure' => true]);
});

it('ignores a forwarded header sent directly by an untrusted client', function (): void {
    $this->withServerVariables([
        'REMOTE_ADDR' => '203.0.113.99',
        'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
    ])->getJson('/test-client-ip')
        ->assertOk()
        ->assertJson(['ip' => '203.0.113.99']);
});
