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

test('user can get repository branches', function () {
    Http::fake([
        'https://api.github.com/repos/gmedia/sakala-api/branches*' => Http::response([
            [
                'name' => 'main',
                'commit' => [
                    'sha' => 'abc123',
                    'url' => 'https://api.github.com/repos/gmedia/sakala-api/commits/abc123',
                ],
            ],
            [
                'name' => 'develop',
                'commit' => [
                    'sha' => 'def456',
                    'url' => 'https://api.github.com/repos/gmedia/sakala-api/commits/def456',
                ],
            ],
        ]),
    ]);

    $response = $this
        ->actingAs($this->user, 'web')
        ->getJson(
            '/api/v1/app/github/repositories/branches?repository_url=https://github.com/gmedia/sakala-api',
        );

    $response
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'main')
        ->assertJsonPath('data.1.name', 'develop');

    Http::assertSent(function ($request) {
        return str_starts_with(
            $request->url(),
            'https://api.github.com/repos/gmedia/sakala-api/branches',
        );
    });
});

test('user can get public repository branches without github oauth account', function () {
    $this->user->oauthAccounts()
        ->where('provider', 'github')
        ->delete();

    Http::fake([
        'https://api.github.com/repos/gmedia/sakala-api/branches*' => Http::response([
            [
                'name' => 'main',
                'commit' => [
                    'sha' => 'abc123',
                ],
            ],
        ]),
    ]);

    $response = $this
        ->actingAs($this->user, 'web')
        ->getJson(
            '/api/v1/app/github/repositories/branches?repository_url=https://github.com/gmedia/sakala-api',
        );

    $response
        ->assertOk()
        ->assertJsonPath('data.0.name', 'main');
});

test('user can get all repository branches across github pagination', function () {
    Http::fake(function ($request) {
        $query = parse_url($request->url(), PHP_URL_QUERY);
        parse_str($query ?? '', $params);

        $page = (int) ($params['page'] ?? 1);

        if ($page === 1) {
            return Http::response(
                [
                    ['name' => 'main'],
                    ['name' => 'develop'],
                ],
                200,
                [
                    'Link' => '<https://api.github.com/repos/gmedia/sakala-api/branches?page=2&per_page=100>; rel="next"',
                ],
            );
        }

        return Http::response([
            ['name' => 'feature/foo'],
            ['name' => 'feature/bar'],
        ]);
    });

    $response = $this
        ->actingAs($this->user, 'web')
        ->getJson(
            '/api/v1/app/github/repositories/branches?repository_url=https://github.com/gmedia/sakala-api',
        );

    $response
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.name', 'main')
        ->assertJsonPath('data.1.name', 'develop')
        ->assertJsonPath('data.2.name', 'feature/foo')
        ->assertJsonPath('data.3.name', 'feature/bar');

    Http::assertSentCount(2);

    Http::assertSent(function ($request) {
        $query = parse_url($request->url(), PHP_URL_QUERY);
        parse_str($query ?? '', $params);

        return (int) ($params['page'] ?? 0) === 1
            && (int) ($params['per_page'] ?? 0) === 100;
    });

    Http::assertSent(function ($request) {
        $query = parse_url($request->url(), PHP_URL_QUERY);
        parse_str($query ?? '', $params);

        return (int) ($params['page'] ?? 0) === 2
            && (int) ($params['per_page'] ?? 0) === 100;
    });
});
