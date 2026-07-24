<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('the first-party console can bootstrap a CSRF cookie', function () {
    $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->get('/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN')
        ->assertHeader('access-control-allow-origin', 'http://app.sakala.localhost:5173')
        ->assertHeader('access-control-allow-credentials', 'true');
});

test('a guest cannot retrieve the current console user', function () {
    $this->getJson(route('api.v1.auth.user'))
        ->assertUnauthorized();
});

test('an authenticated console session can retrieve the current user', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->getJson(route('api.v1.auth.user'))
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.name', $user->name)
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('token');

    expect($user->tokens()->count())->toBe(0);
});

test('logout invalidates the console session', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->postJson(route('api.v1.auth.logout'))
        ->assertNoContent();

    $this->assertGuest('web');

    app('auth')->forgetGuards();

    $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->getJson(route('api.v1.auth.user'))
        ->assertUnauthorized();
});
