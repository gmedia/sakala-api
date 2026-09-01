<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

test('broadcast authentication route requires a web session', function (): void {
    $this->postJson('/broadcasting/auth', [
        'channel_name' => 'private-users.1',
        'socket_id' => '1234.5678',
    ])->assertUnauthorized();
});

test('broadcast authentication route uses the web guard', function (): void {
    $route = Route::getRoutes()->match(Request::create('/broadcasting/auth', 'POST'));

    expect($route->gatherMiddleware())
        ->toContain('web')
        ->toContain('auth:web');
});

test('broadcast authentication endpoint is covered by cors', function (): void {
    expect(config('cors.paths'))->toContain('broadcasting/auth');
});
