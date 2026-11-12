<?php

declare(strict_types=1);

use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectInspectionStatus;
use App\Enums\UserRole;
use App\Models\AgentCommand;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

const PUBLIC_REPO_URL = 'https://github.com/example/app';
const REPO_BODY = [
    'id' => 1,
    'name' => 'app',
    'full_name' => 'example/app',
    'clone_url' => 'https://github.com/example/app.git',
    'default_branch' => 'main',
    'pushed_at' => '2026-08-22T00:00:00Z',
    'private' => false,
];
const HEAD_SHA = '1111111111111111111111111111111111111111';
const NEW_HEAD_SHA = '2222222222222222222222222222222222222222';
const STALE_DEPLOY_SHA = '3333333333333333333333333333333333333333';

function commitsBody(string $sha = HEAD_SHA): array
{
    return [['sha' => $sha, 'commit' => ['message' => 'feat: head']]];
}

function rateLimited(int $status = 403, array $headers = ['X-RateLimit-Remaining' => '0']): mixed
{
    return Http::response(['message' => 'API rate limit exceeded for 203.0.113.1.'], $status, $headers);
}

function publicUser(): User
{
    return User::factory()->create(['role' => UserRole::User]);
}

function validateRepository(object $test): mixed
{
    return $test->postJson(route('api.v1.app.github.repositories.validate'), ['repository_url' => PUBLIC_REPO_URL]);
}

function deployProject(object $test, Project $project): mixed
{
    return $test->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main']);
}

// ─── Rate limit becomes an honest answer ─────────────────────────────────────

test('a rate limited GitHub answer is a stable 503, not a 500', function (mixed $limited, string $retryAfter): void {
    $this->freezeTime();
    Http::fake(['api.github.com/*' => $limited]);

    $this->actingAs(publicUser(), 'web');

    validateRepository($this)
        ->assertStatus(503)
        ->assertJsonPath('code', 'GITHUB_RATE_LIMITED')
        ->assertJsonPath('retry_after', (int) $retryAfter)
        ->assertJsonPath('message', 'Sakala sedang mencapai batas permintaan ke GitHub. Ini bukan masalah di repository kamu. Coba lagi beberapa saat lagi.')
        ->assertHeader('Retry-After', $retryAfter);
})->with([
    'primary limit' => [fn () => rateLimited(403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (now()->getTimestamp() + 120)]), '120'],
    'secondary limit' => [fn () => rateLimited(403, ['Retry-After' => '30']), '30'],
    '429' => [fn () => rateLimited(429, ['Retry-After' => '45']), '45'],
    'message only' => [fn () => Http::response(['message' => 'You have exceeded a secondary rate limit.'], 403), '60'],
    'absurd wait is bounded' => [fn () => rateLimited(429, ['Retry-After' => '99999']), '3600'],
]);

test('the branch list is limited the same way', function (): void {
    Http::fake(['api.github.com/*' => rateLimited()]);

    $this->actingAs(publicUser(), 'web')
        ->getJson(route('api.v1.app.github.repositories.branches', ['repository_url' => PUBLIC_REPO_URL]))
        ->assertStatus(503)
        ->assertJsonPath('code', 'GITHUB_RATE_LIMITED');
});

test('a plain 403 is not mistaken for a rate limit', function (): void {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Forbidden'], 403)]);

    $this->actingAs(publicUser(), 'web');

    validateRepository($this)
        ->assertStatus(503)
        ->assertJsonPath('code', 'GITHUB_UNAVAILABLE');

    // Without a token there is nothing to fall back from.
    Http::assertSentCount(1);
});

test('a limit during ->throw() calls is mapped too', function (): void {
    $response = Http::fake(['api.github.com/*' => rateLimited()]);
    unset($response);

    Route::get('/api/test-github-throw', static function (): void {
        Http::get('https://api.github.com/x')->throw();
    });

    $this->getJson('/api/test-github-throw')
        ->assertStatus(503)
        ->assertJsonPath('code', 'GITHUB_RATE_LIMITED');
});

// ─── Cache and conditional requests ──────────────────────────────────────────

test('repository lookups are shared between users within the cache window', function (): void {
    Http::fake(['api.github.com/*' => Http::response(REPO_BODY)]);

    foreach ([1, 2] as $ignored) {
        $this->actingAs(publicUser(), 'web');
        validateRepository($this)->assertOk();
    }

    Http::assertSentCount(1);
});

test('branch lists are cached across all pages', function (): void {
    Http::fake([
        'api.github.com/repos/example/app/branches?page=1*' => Http::response([['name' => 'main']], 200, ['Link' => '<https://api.github.com/x?page=2>; rel="next"']),
        'api.github.com/repos/example/app/branches?page=2*' => Http::response([['name' => 'dev']]),
    ]);

    $this->actingAs(publicUser(), 'web');

    foreach ([1, 2] as $ignored) {
        $this->getJson(route('api.v1.app.github.repositories.branches', ['repository_url' => PUBLIC_REPO_URL]))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    Http::assertSentCount(2);
});

test('after the window the entry is revalidated with its ETag and a 304 keeps it', function (): void {
    Http::fakeSequence('api.github.com/*')
        ->push(REPO_BODY, 200, ['ETag' => '"abc"'])
        ->push(null, 304);

    $this->actingAs(publicUser(), 'web');

    validateRepository($this)->assertOk();

    $this->travel(301)->seconds();

    validateRepository($this)->assertOk()->assertJsonPath('data.full_name', 'example/app');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->header('If-None-Match') === ['"abc"']);

    // The 304 renewed the window.
    validateRepository($this)->assertOk();
    Http::assertSentCount(2);
});

test('a 404 is cached only briefly', function (): void {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

    $this->actingAs(publicUser(), 'web');

    validateRepository($this)->assertUnprocessable();
    validateRepository($this)->assertUnprocessable();
    Http::assertSentCount(1);

    $this->travel(31)->seconds();

    validateRepository($this)->assertUnprocessable();
    Http::assertSentCount(2);
});

test('a stale answer is served while GitHub limits us', function (): void {
    Http::fakeSequence('api.github.com/*')
        ->push(REPO_BODY, 200, ['ETag' => '"abc"'])
        ->push(['message' => 'API rate limit exceeded'], 403, ['X-RateLimit-Remaining' => '0']);

    $this->actingAs(publicUser(), 'web');

    validateRepository($this)->assertOk();

    $this->travel(301)->seconds();

    validateRepository($this)->assertOk()->assertJsonPath('data.full_name', 'example/app');
});

test('server errors are not cached', function (): void {
    Http::fakeSequence('api.github.com/*')
        ->push(['message' => 'boom'], 500)
        ->push(REPO_BODY);

    $this->actingAs(publicUser(), 'web');

    validateRepository($this)->assertStatus(503);
    validateRepository($this)->assertOk();
});

// ─── GitHub failures are a stable 503, never a 500 ───────────────────────────

test('a GitHub server error is GITHUB_UNAVAILABLE, not a 500', function (int $status): void {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Server Error'], $status)]);

    $this->actingAs(publicUser(), 'web');

    validateRepository($this)
        ->assertStatus(503)
        ->assertExactJson([
            'message' => 'Sakala belum bisa mengambil data repository dari GitHub. Coba lagi beberapa saat lagi.',
            'code' => 'GITHUB_UNAVAILABLE',
        ]);
})->with([500, 502, 503]);

test('an unreadable GitHub answer is GITHUB_UNAVAILABLE', function (): void {
    Http::fake(['api.github.com/*' => Http::response('not json', 200)]);

    $this->actingAs(publicUser(), 'web');

    validateRepository($this)
        ->assertStatus(503)
        ->assertJsonPath('code', 'GITHUB_UNAVAILABLE')
        ->assertJsonPath('message', 'Jawaban GitHub soal repository ini tidak bisa dibaca. Coba lagi beberapa saat lagi.');
});

test('GitHub out of reach is GITHUB_UNAVAILABLE', function (): void {
    Http::fake(['api.github.com/*' => Http::failedConnection()]);

    $this->actingAs(publicUser(), 'web');

    validateRepository($this)
        ->assertStatus(503)
        ->assertJsonPath('code', 'GITHUB_UNAVAILABLE');
});

test('a branch list failure is GITHUB_UNAVAILABLE', function (): void {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Server Error'], 502)]);

    $this->actingAs(publicUser(), 'web')
        ->getJson(route('api.v1.app.github.repositories.branches', ['repository_url' => PUBLIC_REPO_URL]))
        ->assertStatus(503)
        ->assertJsonPath('code', 'GITHUB_UNAVAILABLE')
        ->assertJsonPath('message', 'Sakala belum bisa mengambil daftar branch dari GitHub. Coba lagi beberapa saat lagi.');
});

test('a deployment whose branch head GitHub cannot give is GITHUB_UNAVAILABLE', function (): void {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Server Error'], 500)]);

    $user = publicUser();
    $project = Project::factory()->for($user)->create(['branch' => 'main', 'repository_full_name' => 'example/app']);

    $this->actingAs($user, 'web');

    deployProject($this, $project)
        ->assertStatus(503)
        ->assertJsonPath('code', 'GITHUB_UNAVAILABLE');

    expect(Deployment::query()->where('project_id', $project->id)->count())->toBe(0);
});

// ─── Server token ────────────────────────────────────────────────────────────

test('without a token public reads stay anonymous', function (): void {
    Http::fake(['api.github.com/*' => Http::response(REPO_BODY)]);

    $this->actingAs(publicUser(), 'web');
    validateRepository($this)->assertOk();

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

test('a configured token authenticates reads and goes nowhere else', function (): void {
    config()->set('services.github.public_api_token', 'ghp_servertokenvalue');
    Http::fake([
        'api.github.com/repos/example/app/commits*' => Http::response(commitsBody()),
        'api.github.com/*' => Http::response(REPO_BODY),
    ]);

    $user = publicUser();
    $project = Project::factory()->for($user)->create(['branch' => 'main', 'repository_url' => PUBLIC_REPO_URL, 'repository_full_name' => 'example/app']);

    $this->actingAs($user, 'web');

    $validate = validateRepository($this)->assertOk();
    $deploy = deployProject($this, $project)->assertCreated();

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer ghp_servertokenvalue']);

    $command = AgentCommand::query()->where('project_id', $project->id)->where('type', AgentCommandType::DeployProject)->sole();

    expect($validate->getContent())->not->toContain('ghp_servertokenvalue')
        ->and($deploy->getContent())->not->toContain('ghp_servertokenvalue')
        ->and(json_encode($command->payload))->not->toContain('ghp_servertokenvalue');
});

const ORG_TOKEN_POLICY = "The 'example' organization forbids access via a fine-grained personal access tokens if the token's lifetime is greater than 366 days. Please adjust your token's lifetime at the following URL: https://github.com/settings/personal-access-tokens/1";

function sentWithToken(Request $request): bool
{
    return $request->hasHeader('Authorization');
}

test('a refused token falls back to one anonymous read', function (int $status, array $body, array $headers): void {
    config()->set('services.github.public_api_token', 'ghp_refused');
    Log::spy();
    Http::fakeSequence('api.github.com/*')
        ->push($body, $status, $headers)
        ->push(REPO_BODY);

    $this->actingAs(publicUser(), 'web');

    validateRepository($this)->assertOk()->assertJsonPath('data.full_name', 'example/app');

    Http::assertSentCount(2);
    $sent = Http::recorded()->map(fn (array $pair): bool => sentWithToken($pair[0]))->all();
    expect($sent)->toBe([true, false]);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'GITHUB_PUBLIC_API_TOKEN')
            && $context['status'] === $status
            && $context['github_message'] === $body['message']
            && ! str_contains($message.json_encode($context), 'ghp_refused'),
    );
})->with([
    'revoked token' => [401, ['message' => 'Bad credentials'], []],
    'organization token lifetime policy' => [403, ['message' => ORG_TOKEN_POLICY], ['X-RateLimit-Remaining' => '4999']],
    'SAML SSO enforcement' => [403, ['message' => 'Resource protected by organization SAML enforcement. You must grant your Personal Access token access to this organization.'], ['X-GitHub-SSO' => 'required; url=https://github.com/orgs/example/sso?authorization_request=x']],
]);

test('a refused token is logged once per cache window', function (): void {
    config()->set('services.github.public_api_token', 'ghp_refused');
    Log::spy();
    Http::fake(fn (Request $request) => sentWithToken($request)
        ? Http::response(['message' => ORG_TOKEN_POLICY], 403)
        : Http::response(str_ends_with($request->toPsrRequest()->getUri()->getPath(), '/branches') ? [['name' => 'main']] : REPO_BODY));

    $this->actingAs(publicUser(), 'web');

    validateRepository($this)->assertOk();
    $this->getJson(route('api.v1.app.github.repositories.branches', ['repository_url' => PUBLIC_REPO_URL]))->assertOk();

    Log::shouldHaveReceived('warning')->once();

    $this->travel(301)->seconds();
    $this->getJson(route('api.v1.app.github.repositories.branches', ['repository_url' => PUBLIC_REPO_URL.'-other']))->assertOk();

    Log::shouldHaveReceived('warning')->twice();
});

test('a rate limited token is not retried anonymously', function (mixed $limited): void {
    config()->set('services.github.public_api_token', 'ghp_servertokenvalue');
    Http::fake(['api.github.com/*' => $limited]);

    $this->actingAs(publicUser(), 'web');

    validateRepository($this)
        ->assertStatus(503)
        ->assertJsonPath('code', 'GITHUB_RATE_LIMITED');

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => ! sentWithToken($request));
})->with([
    'primary limit' => [fn () => rateLimited()],
    'secondary limit' => [fn () => rateLimited(403, ['Retry-After' => '30'])],
    '429' => [fn () => rateLimited(429, ['Retry-After' => '45'])],
]);

test('a limit on the anonymous retry is still GITHUB_RATE_LIMITED', function (): void {
    config()->set('services.github.public_api_token', 'ghp_refused');
    Http::fakeSequence('api.github.com/*')
        ->push(['message' => ORG_TOKEN_POLICY], 403)
        ->pushResponse(rateLimited());

    $this->actingAs(publicUser(), 'web');

    validateRepository($this)
        ->assertStatus(503)
        ->assertJsonPath('code', 'GITHUB_RATE_LIMITED');
});

test('the anonymous fallback keeps ETag revalidation', function (): void {
    config()->set('services.github.public_api_token', 'ghp_refused');
    Http::fakeSequence('api.github.com/*')
        ->push(['message' => ORG_TOKEN_POLICY], 403)
        ->push(REPO_BODY, 200, ['ETag' => '"anon"'])
        ->push(['message' => ORG_TOKEN_POLICY], 403)
        ->push(null, 304);

    $this->actingAs(publicUser(), 'web');

    validateRepository($this)->assertOk();

    $this->travel(301)->seconds();

    validateRepository($this)->assertOk()->assertJsonPath('data.full_name', 'example/app');

    Http::assertSentCount(4);
    $revalidations = Http::recorded()->slice(2)->map(fn (array $pair): array => $pair[0]->header('If-None-Match'))->values()->all();
    expect($revalidations)->toBe([['"anon"'], ['"anon"']]);

    // The 304 renewed the shared entry.
    validateRepository($this)->assertOk();
    Http::assertSentCount(4);
});

test('with a token, a private repository stays hidden in the public flow', function (): void {
    config()->set('services.github.public_api_token', 'ghp_servertokenvalue');
    Http::fake([
        'api.github.com/repos/secret/repo/branches*' => Http::response([['name' => 'hidden-branch']]),
        'api.github.com/repos/secret/repo/commits*' => Http::response(commitsBody()),
        'api.github.com/repos/secret/repo' => Http::response([...REPO_BODY, 'full_name' => 'secret/repo', 'private' => true]),
    ]);

    $user = publicUser();
    $project = Project::factory()->for($user)->create(['branch' => 'main', 'repository_full_name' => 'secret/repo', 'repository_url' => 'https://github.com/secret/repo']);

    $this->actingAs($user, 'web');

    $branches = $this->getJson(route('api.v1.app.github.repositories.branches', ['repository_url' => 'https://github.com/secret/repo']))
        ->assertUnprocessable();

    expect($branches->getContent())->not->toContain('hidden-branch');

    deployProject($this, $project)->assertUnprocessable()->assertJsonValidationErrors(['branch']);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/commits') || str_contains($request->url(), '/branches'));
});

// ─── Deploy and inspection fallbacks ─────────────────────────────────────────

test('a deployment asks GitHub again, so a fresh push is deployed', function (): void {
    Http::fakeSequence('api.github.com/*')
        ->push(commitsBody(HEAD_SHA), 200, ['ETag' => '"one"'])
        ->push(commitsBody(NEW_HEAD_SHA), 200, ['ETag' => '"two"']);

    $user = publicUser();
    $project = Project::factory()->for($user)->create(['branch' => 'main', 'repository_full_name' => 'example/app']);

    $this->actingAs($user, 'web');

    $first = deployProject($this, $project)->assertCreated()->json('data.commit_sha');
    Deployment::query()->whereKey(deployIdOf($project))->update(['status' => DeploymentStatus::Failed]);
    $second = deployProject($this, $project)->assertCreated()->json('data.commit_sha');

    expect($first)->toBe(HEAD_SHA)->and($second)->toBe(NEW_HEAD_SHA);
});

test('a redeploy while limited uses the last known head', function (): void {
    Http::fakeSequence('api.github.com/*')
        ->push(commitsBody(HEAD_SHA), 200, ['ETag' => '"one"'])
        ->push(['message' => 'API rate limit exceeded'], 403, ['X-RateLimit-Remaining' => '0']);

    $user = publicUser();
    $project = Project::factory()->for($user)->create(['branch' => 'main', 'repository_full_name' => 'example/app']);

    $this->actingAs($user, 'web');

    deployProject($this, $project)->assertCreated();
    Deployment::query()->whereKey(deployIdOf($project))->update(['status' => DeploymentStatus::Failed]);

    deployProject($this, $project)->assertCreated()->assertJsonPath('data.commit_sha', HEAD_SHA);
});

test('with an empty cache a redeploy falls back to the last deployment on that branch', function (): void {
    Http::fake(['api.github.com/*' => rateLimited()]);

    $user = publicUser();
    $project = Project::factory()->for($user)->create(['branch' => 'main', 'repository_full_name' => 'example/app']);
    Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
        'branch' => 'main',
        'commit_sha' => STALE_DEPLOY_SHA,
        'commit_message' => 'old',
    ]);

    $this->actingAs($user, 'web');

    deployProject($this, $project)
        ->assertCreated()
        ->assertJsonPath('data.commit_sha', STALE_DEPLOY_SHA)
        ->assertJsonPath('data.commit_message', 'old');
});

test('a first deployment with nothing known answers 503', function (): void {
    Http::fake(['api.github.com/*' => rateLimited()]);

    $user = publicUser();
    $project = Project::factory()->for($user)->create(['branch' => 'main', 'repository_full_name' => 'example/app']);

    $this->actingAs($user, 'web');

    deployProject($this, $project)
        ->assertStatus(503)
        ->assertJsonPath('code', 'GITHUB_RATE_LIMITED');

    expect(Deployment::query()->where('project_id', $project->id)->count())->toBe(0);
});

test('creating a project survives a limit, with an honest preview state', function (): void {
    Http::fake(['api.github.com/*' => rateLimited()]);

    $user = publicUser();

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/app/projects', ['name' => 'Limited', 'repository_url' => PUBLIC_REPO_URL, 'branch' => 'main'])
        ->assertCreated()
        ->assertJsonPath('data.preview_status', ProjectInspectionStatus::Unavailable->value)
        ->assertJsonPath('data.inspection_error_code', 'github_rate_limited');
});

test('the shared cache never holds the token', function (): void {
    config()->set('services.github.public_api_token', 'ghp_servertokenvalue');
    Cache::spy();
    Http::fake(['api.github.com/*' => Http::response(REPO_BODY)]);

    $this->actingAs(publicUser(), 'web');
    validateRepository($this)->assertOk();

    Cache::shouldHaveReceived('put')->withArgs(
        fn (string $key, mixed $value): bool => ! str_contains($key.json_encode($value), 'ghp_servertokenvalue'),
    );
});

function deployIdOf(Project $project): string
{
    return (string) Deployment::query()->where('project_id', $project->id)->orderByDesc('sequence')->value('id');
}
