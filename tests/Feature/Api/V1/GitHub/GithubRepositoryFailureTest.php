<?php

declare(strict_types=1);

use App\Models\OAuthAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();

    OAuthAccount::factory()->create([
        'user_id' => $this->user->id,
        'provider' => 'github',
        'access_token' => 'github-test-token',
        'provider_username' => 'test-user',
    ]);
});

test('github api failure is handled when listing repositories', function () {
    Http::fake([
        'https://api.github.com/user/repos*' => Http::response(
            ['message' => 'Internal Server Error'],
            500,
        ),
    ]);

    $response = $this
        ->actingAs($this->user, 'web')
        ->getJson('/api/v1/app/github/repositories');

    $response->assertServerError();
});

test('github api failure is handled when searching repositories', function () {
    Http::fake([
        'https://api.github.com/search/repositories*' => Http::response(
            ['message' => 'Internal Server Error'],
            500,
        ),
    ]);

    $response = $this
        ->actingAs($this->user, 'web')
        ->getJson(
            '/api/v1/app/github/repositories?search=sakala',
        );

    $response->assertServerError();
});

test('github api failure is handled when counting repositories', function () {
    Http::fake([
        'https://api.github.com/user' => Http::response(
            ['message' => 'Internal Server Error'],
            500,
        ),
    ]);

    $response = $this
        ->actingAs($this->user, 'web')
        ->getJson('/api/v1/app/github/repositories/count');

    $response->assertServerError();
});

test('github api failure is handled when getting branches', function () {
    Http::fake([
        'https://api.github.com/repos/gmedia/sakala-api/branches*' => Http::response(
            ['message' => 'Internal Server Error'],
            500,
        ),
    ]);

    $response = $this
        ->actingAs($this->user, 'web')
        ->getJson(
            '/api/v1/app/github/repositories/branches?repository_url=https://github.com/gmedia/sakala-api',
        );

    $response->assertServerError();
});
