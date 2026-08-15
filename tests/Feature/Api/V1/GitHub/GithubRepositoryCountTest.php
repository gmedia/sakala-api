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

test('user can get repository count using owned private repositories', function () {
    Http::fake([
        'https://api.github.com/user' => Http::response([
            'public_repos' => 5,
            'total_private_repos' => 20,
            'owned_private_repos' => 7,
        ]),
    ]);

    $response = $this
        ->actingAs($this->user, 'web')
        ->getJson('/api/v1/app/github/repositories/count');

    $response
        ->assertOk()
        ->assertJsonPath('data.total_repositories', 12);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.github.com/user';
    });
});
