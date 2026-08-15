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

test('user can validate existing repository', function () {
    Http::fake([
        'https://api.github.com/repos/gmedia/sakala-api' => Http::response([
            'id' => 1,
            'name' => 'sakala-api',
            'full_name' => 'gmedia/sakala-api',
            'clone_url' => 'https://github.com/gmedia/sakala-api.git',
            'default_branch' => 'main',
            'pushed_at' => '2026-08-01T10:00:00Z',
            'private' => false,
        ]),
    ]);

    $response = $this
        ->actingAs($this->user, 'web')
        ->postJson(
            '/api/v1/app/github/repositories/validate',
            [
                'repository_url' => 'https://github.com/gmedia/sakala-api',
            ],
        );

    $response
        ->assertOk()
        ->assertJsonPath('data.id', '1')
        ->assertJsonPath('data.name', 'sakala-api')
        ->assertJsonPath('data.full_name', 'gmedia/sakala-api')
        ->assertJsonPath('data.private', false);
});

test('validation fails when repository does not exist', function () {
    Http::fake([
        'https://api.github.com/repos/gmedia/not-found' => Http::response(
            [
                'message' => 'Not Found',
            ],
            404,
        ),
    ]);

    $response = $this
        ->actingAs($this->user, 'web')
        ->postJson(
            '/api/v1/app/github/repositories/validate',
            [
                'repository_url' => 'https://github.com/gmedia/not-found',
            ],
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'repository_url',
        ]);
});

test('validation fails for invalid repository url', function () {
    Http::fake();

    $response = $this
        ->actingAs($this->user, 'web')
        ->postJson(
            '/api/v1/app/github/repositories/validate',
            [
                'repository_url' => 'https://example.com/foo/bar',
            ],
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'repository_url',
        ]);

    Http::assertNothingSent();
});

test('user can validate public repository without github oauth account', function () {
    $this->user->oauthAccounts()
        ->where('provider', 'github')
        ->delete();

    Http::fake([
        'https://api.github.com/repos/gmedia/sakala-api' => Http::response([
            'id' => 1,
            'name' => 'sakala-api',
            'full_name' => 'gmedia/sakala-api',
            'clone_url' => 'https://github.com/gmedia/sakala-api.git',
            'default_branch' => 'main',
            'pushed_at' => '2026-08-01T10:00:00Z',
            'private' => false,
        ]),
    ]);

    $response = $this
        ->actingAs($this->user, 'web')
        ->postJson(
            '/api/v1/app/github/repositories/validate',
            [
                'repository_url' => 'https://github.com/gmedia/sakala-api',
            ],
        );

    $response
        ->assertOk()
        ->assertJsonPath('data.name', 'sakala-api')
        ->assertJsonPath('data.private', false);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.github.com/repos/gmedia/sakala-api'
            && ! $request->hasHeader('Authorization');
    });
});
