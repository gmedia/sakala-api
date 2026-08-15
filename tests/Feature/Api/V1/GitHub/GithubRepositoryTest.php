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

test('user can get github repositories', function () {
    Http::fake([
        'https://api.github.com/user/repos*' => Http::response([
            [
                'id' => 1,
                'name' => 'sakala-api',
                'full_name' => 'gmedia/sakala-api',
                'clone_url' => 'https://github.com/gmedia/sakala-api.git',
                'default_branch' => 'main',
                'pushed_at' => '2026-08-01T10:00:00Z',
                'private' => false,
            ],
            [
                'id' => 2,
                'name' => 'other-project',
                'full_name' => 'gmedia/other-project',
                'clone_url' => 'https://github.com/gmedia/other-project.git',
                'default_branch' => 'main',
                'pushed_at' => '2026-08-01T10:00:00Z',
                'private' => true,
            ],
        ]),
    ]);

    $response = $this
        ->actingAs($this->user, 'web')
        ->getJson('/api/v1/app/github/repositories');

    $response
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', '1')
        ->assertJsonPath('data.0.name', 'sakala-api')
        ->assertJsonPath('data.0.full_name', 'gmedia/sakala-api')
        ->assertJsonPath('data.0.private', false)
        ->assertJsonPath('data.1.id', '2')
        ->assertJsonPath('data.1.name', 'other-project')
        ->assertJsonPath('data.1.full_name', 'gmedia/other-project')
        ->assertJsonPath('data.1.private', true);

    Http::assertSent(function ($request) {
        $query = parse_url($request->url(), PHP_URL_QUERY);
        parse_str($query ?? '', $params);

        return $request->url() !== ''
            && $params['visibility'] === 'all'
            && $params['affiliation'] === 'owner'
            && $params['sort'] === 'updated'
            && $params['direction'] === 'desc'
            && (int) $params['page'] === 1
            && (int) $params['per_page'] === 5;
    });
});

test('user can paginate github repositories', function () {
    Http::fake([
        'https://api.github.com/user/repos*' => Http::response(
            [
                [
                    'id' => 3,
                    'name' => 'third-project',
                    'full_name' => 'gmedia/third-project',
                    'clone_url' => 'https://github.com/gmedia/third-project.git',
                    'default_branch' => 'main',
                    'pushed_at' => '2026-08-01T10:00:00Z',
                    'private' => false,
                ],
            ],
            200,
            [
                'Link' => '<https://api.github.com/user/repos?page=3&per_page=5>; rel="last"',
            ],
        ),
    ]);

    $response = $this
        ->actingAs($this->user, 'web')
        ->getJson(
            '/api/v1/app/github/repositories?page=2&per_page=5',
        );

    $response
        ->assertOk()
        ->assertJsonPath('meta.page', 2)
        ->assertJsonPath('meta.per_page', 5)
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonPath('meta.has_next_page', true)
        ->assertJsonPath('meta.has_previous_page', true);

    Http::assertSent(function ($request) {
        $query = parse_url($request->url(), PHP_URL_QUERY);
        parse_str($query ?? '', $params);

        return (int) $params['page'] === 2
            && (int) $params['per_page'] === 5;
    });
});


test('github access token is not returned', function () {
    Http::fake([
        'https://api.github.com/user/repos*' => Http::response([
            [
                'id' => 1,
                'name' => 'sakala-api',
                'full_name' => 'gmedia/sakala-api',
                'clone_url' => 'https://github.com/gmedia/sakala-api.git',
                'default_branch' => 'main',
                'pushed_at' => '2026-08-01T10:00:00Z',
                'private' => true,
            ],
        ]),
    ]);

    $response = $this
        ->actingAs($this->user, 'web')
        ->getJson('/api/v1/app/github/repositories');

    $response
        ->assertOk()
        ->assertJsonMissing([
            'access_token' => 'github-test-token',
        ])
        ->assertJsonMissing([
            'github-test-token',
        ]);
});