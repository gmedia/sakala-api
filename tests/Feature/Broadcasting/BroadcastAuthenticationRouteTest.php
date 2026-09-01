<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function useTestReverbBroadcaster(): void
{
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'test-key');
    config()->set('broadcasting.connections.reverb.secret', 'test-secret');
    config()->set('broadcasting.connections.reverb.app_id', 'test-app');
    config()->set('broadcasting.connections.reverb.options', [
        'host' => '127.0.0.1',
        'port' => 8080,
        'scheme' => 'http',
        'useTLS' => false,
    ]);

    Broadcast::forgetDrivers();

    require base_path('routes/channels.php');
}

afterEach(function (): void {
    Broadcast::forgetDrivers();
});

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

test('authenticated user can authenticate their private user channel', function (): void {
    useTestReverbBroadcaster();

    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->postJson('/broadcasting/auth', [
            'channel_name' => "private-users.{$user->id}",
            'socket_id' => '1234.5678',
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);
});

test('authenticated user cannot authenticate another users private channel', function (): void {
    useTestReverbBroadcaster();

    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($user, 'web')
        ->postJson('/broadcasting/auth', [
            'channel_name' => "private-users.{$otherUser->id}",
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});
