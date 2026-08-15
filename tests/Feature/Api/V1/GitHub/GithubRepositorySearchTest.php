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

test('user can search github repositories', function () {
    Http::fake([
        'https://api.github.com/search/repositories*' => Http::response([
            'total_count' => 1,
            'items' => [
                [
                    'id' => 1,
                    'name' => 'sakala-api',
                    'full_name' => 'gmedia/sakala-api',
                    'clone_url' => 'https://github.com/gmedia/sakala-api.git',
                    'default_branch' => 'main',
                    'pushed_at' => '2026-08-01T10:00:00Z',
                    'private' => false,
                ],
            ],
        ]),
    ]);

    $response = $this
        ->actingAs($this->user, 'web')
        ->getJson(
            '/api/v1/app/github/repositories?search=sakala',
        );

    $response
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', '1')
        ->assertJsonPath('data.0.name', 'sakala-api');

    Http::assertSent(function ($request) {
        return str_starts_with(
            $request->url(),
            'https://api.github.com/search/repositories',
        )
            && $request['q'] === 'user:test-user sakala'
            && $request['sort'] === 'updated'
            && $request['order'] === 'desc';
    });
});
