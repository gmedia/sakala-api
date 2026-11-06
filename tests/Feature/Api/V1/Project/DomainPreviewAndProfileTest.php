<?php

declare(strict_types=1);

use App\Enums\GithubInstallationStatus;
use App\Models\GithubInstallation;
use App\Models\OAuthAccount;
use App\Models\Project;
use App\Models\User;
use App\Services\GitHub\GithubInstallationService;
use App\Support\Domains\ProjectDomainGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// ─── Domain preview ──────────────────────────────────────────────────────────

test('the domain preview returns the final generated domain', function (): void {
    config()->set('sakala.project.default_domain', 'apps.kampus.test');
    app()->forgetInstance(ProjectDomainGenerator::class);

    $this->actingAs(User::factory()->create(), 'web')
        ->getJson(route('api.v1.app.projects.domain-preview', ['name' => 'My Great App']))
        ->assertOk()
        ->assertExactJson(['data' => [
            'slug' => 'my-great-app',
            'domain' => 'my-great-app.apps.kampus.test',
        ]]);
});

test('the domain preview shows the suffix a collision adds', function (): void {
    Project::factory()->create(['slug' => 'portfolio']);

    $this->actingAs(User::factory()->create(), 'web')
        ->getJson(route('api.v1.app.projects.domain-preview', ['name' => 'Portfolio']))
        ->assertOk()
        ->assertJsonPath('data.slug', 'portfolio-1');
});

test('the domain preview explains a reserved name and validates input', function (): void {
    $this->getJson(route('api.v1.app.projects.domain-preview', ['name' => 'x']))->assertUnauthorized();

    $this->actingAs(User::factory()->create(), 'web');

    $this->getJson(route('api.v1.app.projects.domain-preview', ['name' => 'admin']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->getJson(route('api.v1.app.projects.domain-preview'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('the preview route is not swallowed by the project show route', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $this->actingAs($user, 'web')->getJson("/api/v1/app/projects/{$project->id}")->assertOk();
});

// ─── Repository pagination ───────────────────────────────────────────────────

function connectedInstallation(User $user): GithubInstallation
{
    OAuthAccount::factory()->for($user)->create(['access_token' => 'user-access-token']);

    $installation = GithubInstallation::query()->create([
        'github_installation_id' => 100,
        'account_id' => 10,
        'account_login' => 'sakala',
        'account_type' => 'User',
        'repository_selection' => 'selected',
        'permissions' => ['contents' => 'read'],
        'status' => GithubInstallationStatus::Active,
    ]);
    $installation->users()->attach($user, ['last_verified_at' => now()]);

    return $installation;
}

/** @return array<string, mixed> */
function githubRepo(int $id, string $name): array
{
    return [
        'id' => $id,
        'name' => $name,
        'full_name' => "sakala/{$name}",
        'private' => false,
        'clone_url' => "https://github.com/sakala/{$name}.git",
        'default_branch' => 'main',
        'pushed_at' => '2026-08-22T00:00:00Z',
    ];
}

test('installation repositories expose page metadata and working links', function (): void {
    $user = User::factory()->create();
    $installation = connectedInstallation($user);

    Http::fake(['api.github.com/user/installations/100/repositories*' => Http::response([
        'total_count' => 45,
        'repositories' => [githubRepo(1, 'one'), githubRepo(2, 'two')],
    ])]);

    $response = $this->actingAs($user, 'web')
        ->getJson(route('api.v1.app.github.installations.repositories.index', [$installation, 'page' => 2, 'per_page' => 2]))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 45)
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 23);

    expect($response->json('links.next'))->toContain('/api/v1/app/github/installations/')->toContain('page=3');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'page=2') && str_contains($request->url(), 'per_page=2'));
});

test('search looks through more than the first page', function (): void {
    $user = User::factory()->create();
    $installation = connectedInstallation($user);

    $firstPage = array_map(static fn (int $id): array => githubRepo($id, "repo-{$id}"), range(1, 100));

    Http::fake([
        'api.github.com/user/installations/100/repositories?page=1*' => Http::response(['total_count' => 101, 'repositories' => $firstPage]),
        'api.github.com/user/installations/100/repositories?page=2*' => Http::response(['total_count' => 101, 'repositories' => [githubRepo(101, 'Needle-App')]]),
    ]);

    $this->actingAs($user, 'web')
        ->getJson(route('api.v1.app.github.installations.repositories.index', [$installation, 'search' => 'needle']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 1);
});

test('search stops at its page limit', function (): void {
    $user = User::factory()->create();
    $installation = connectedInstallation($user);

    Http::fake(['api.github.com/user/installations/100/repositories*' => Http::response([
        'total_count' => 5000,
        'repositories' => array_map(static fn (int $id): array => githubRepo($id, "repo-{$id}"), range(1, 100)),
    ])]);

    app(GithubInstallationService::class)->repositories($user, $installation, 1, 30, 'absent');

    Http::assertSentCount(10);
});

test('another user cannot list the repositories of an installation', function (): void {
    $installation = connectedInstallation(User::factory()->create());

    $this->actingAs(User::factory()->create(), 'web')
        ->getJson(route('api.v1.app.github.installations.repositories.index', $installation))
        ->assertForbidden();
});

// ─── GitHub install return ───────────────────────────────────────────────────

test('a guest starting the GitHub install lands on the console login', function (): void {
    $this->get(route('auth.github.install'))
        ->assertRedirect(config('sakala.console_url').'/login');
});

test('an unconfigured GitHub App goes back to the wizard', function (): void {
    config()->set('services.github_app.slug', '');

    $this->actingAs(User::factory()->create(), 'web')
        ->get(route('auth.github.install'))
        ->assertRedirect(config('sakala.console_url').'/projects/new?github_installation=error&error=github_app_not_configured');
});

test('a setup without an installation ID goes back to the wizard', function (): void {
    $this->actingAs(User::factory()->create(), 'web')
        ->withSession(['github_app_installation_state' => 'state-1'])
        ->get(route('auth.github.setup', ['state' => 'state-1']))
        ->assertRedirect(config('sakala.console_url').'/projects/new?github_installation=error&error=github_invalid_installation');
});

test('a GitHub failure during setup goes back to the wizard', function (): void {
    $user = User::factory()->create();
    OAuthAccount::factory()->for($user)->create(['access_token' => 'user-access-token']);

    Http::fake(['api.github.com/*' => Http::response([], 500)]);

    $this->actingAs($user, 'web')
        ->withSession(['github_app_installation_state' => 'state-1'])
        ->get(route('auth.github.setup', ['state' => 'state-1', 'installation_id' => 100]))
        ->assertRedirect()
        ->assertRedirectContains('/projects/new?github_installation=error');
});

// ─── Avatar removal ──────────────────────────────────────────────────────────

test('removing an avatar deletes the file and clears the OAuth copy', function (): void {
    Storage::fake('avatars');
    $path = UploadedFile::fake()->image('a.png')->store('avatars', 'avatars');
    $user = User::factory()->create(['avatar_path' => $path, 'avatar_url' => 'https://avatars.example/a.png']);

    $this->actingAs($user, 'web')
        ->deleteJson(route('api.v1.app.profile.avatar.destroy'))
        ->assertOk()
        ->assertJsonPath('data.avatar_url', null);

    Storage::disk('avatars')->assertMissing($path);

    expect($user->fresh()->avatar_path)->toBeNull()
        ->and($user->fresh()->avatar_url)->toBeNull();

    // A second removal is a no-op.
    $this->deleteJson(route('api.v1.app.profile.avatar.destroy'))->assertOk();
});

test('removing an avatar requires a session', function (): void {
    $this->deleteJson(route('api.v1.app.profile.avatar.destroy'))->assertUnauthorized();
});

// ─── Onboarding profiles ─────────────────────────────────────────────────────

test('student and mentor are valid onboarding profiles', function (string $role): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.onboarding.profile'), ['name' => 'Budi', 'role' => $role])
        ->assertSuccessful();

    expect($user->fresh()->onboarding_role->value)->toBe($role);
})->with(['student', 'mentor']);

test('an unknown onboarding profile is still rejected', function (): void {
    $this->actingAs(User::factory()->create(), 'web')
        ->postJson(route('api.v1.onboarding.profile'), ['name' => 'Budi', 'role' => 'wizard'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role']);
});
