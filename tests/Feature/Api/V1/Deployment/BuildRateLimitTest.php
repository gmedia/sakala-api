<?php

declare(strict_types=1);

use App\Actions\Deployment\CreateDeploymentAction;
use App\Data\Deployment\CreateDeploymentData;
use App\Enums\DeploymentStatus;
use App\Enums\UsageSignalType;
use App\Enums\UserRole;
use App\Exceptions\Runtime\BuildRateLimitExceededException;
use App\Models\Deployment;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Models\UsageSignalRecord;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Runtime\PilotRuntimeLimitService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const BUILD_RATE_NOW = '2026-11-18 10:00:00';

beforeEach(function (): void {
    Queue::fake();
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => '3e91b22a2e560a9f42b9d0921ca9b66c94462e5d', 'commit' => ['message' => 'feat: build rate']],
        ], 200),
        '*' => Http::response([], 404),
    ]);

    $this->travelTo(CarbonImmutable::parse(BUILD_RATE_NOW));
});

/** A project in a personal workspace whose profile allows $limit builds per hour. */
function buildRateProject(?int $limit, ?User $owner = null): Project
{
    $owner ??= User::factory()->create(['role' => UserRole::User]);
    $profile = LimitProfile::factory()->create(['max_builds_per_hour' => $limit, 'max_active_deployments' => 10, 'max_active_containers' => 10]);
    Workspace::query()->whereKey($owner->personalWorkspace()->value('id'))->update(['limit_profile_id' => $profile->id]);

    return Project::factory()->for($owner)->create(['branch' => 'main']);
}

/** Finished builds of the project, created $secondsAgo before the frozen now. */
function seedBuilds(Project $project, int $count, int $secondsAgo, DeploymentStatus $status = DeploymentStatus::Succeeded): void
{
    Deployment::factory()->count($count)->for($project)->create([
        'status' => $status,
        'created_at' => now()->subSeconds($secondsAgo),
    ]);
}

function deployAsOwner(Project $project, ?User $actor = null): Deployment
{
    return app(CreateDeploymentAction::class)->handle(
        project: $project,
        user: $actor ?? $project->user,
        data: new CreateDeploymentData(branch: 'main'),
    );
}

test('a workspace builds up to its limit and the next deployment is refused with when to try again', function (): void {
    $project = buildRateProject(3);
    seedBuilds($project, 1, 1800);
    seedBuilds($project, 1, 600, DeploymentStatus::Failed);

    $third = $this->actingAs($project->user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertCreated();

    Deployment::query()->whereKey($third->json('data.id'))->update(['status' => DeploymentStatus::Succeeded]);

    $this->actingAs($project->user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'Workspace ini sudah menjalankan 3 build dalam 60 menit terakhir, dan itu batasnya. Coba deploy lagi sekitar 30 menit lagi.',
            'code' => 'BUILD_RATE_LIMIT_EXCEEDED',
            'limit' => 3,
            'current' => 3,
            'retry_after_seconds' => 1800,
        ]);

    expect(Deployment::query()->where('project_id', $project->id)->count())->toBe(3);
});

test('only builds created within the last 3600 seconds count', function (int $secondsAgo, bool $counted): void {
    $project = buildRateProject(1);
    seedBuilds($project, 1, $secondsAgo);

    if ($counted) {
        expect(fn () => deployAsOwner($project))->toThrow(BuildRateLimitExceededException::class);

        return;
    }

    expect(deployAsOwner($project)->status)->toBe(DeploymentStatus::Queued);
})->with([
    'one second past the window' => [3601, false],
    'exactly one hour ago' => [3600, false],
    'one second inside the window' => [3599, true],
    'just now' => [0, true],
]);

test('a build one second inside the window frees its slot in one second', function (): void {
    $project = buildRateProject(1);
    seedBuilds($project, 1, 3599);

    try {
        deployAsOwner($project);
        $this->fail('The build rate limit did not hold.');
    } catch (BuildRateLimitExceededException $e) {
        expect($e->retryAfterSeconds)->toBe(1)
            ->and($e->getMessage())->toEndWith('Coba deploy lagi sekitar 1 detik lagi.');
    }
});

test('every status counts, failed and cancelled builds included', function (DeploymentStatus $status): void {
    $project = buildRateProject(1);
    seedBuilds($project, 1, 60, $status);

    expect(fn () => deployAsOwner($project))->toThrow(BuildRateLimitExceededException::class);
})->with([
    DeploymentStatus::Succeeded,
    DeploymentStatus::Failed,
    DeploymentStatus::Cancelled,
]);

test('builds of a deleted project of the workspace still count', function (): void {
    $project = buildRateProject(2);
    $deleted = Project::factory()->for($project->user)->create(['branch' => 'main']);
    seedBuilds($deleted, 2, 300);
    $deleted->delete();

    expect(fn () => deployAsOwner($project))->toThrow(BuildRateLimitExceededException::class);
});

test('builds of another workspace do not count', function (): void {
    $project = buildRateProject(1);
    seedBuilds(buildRateProject(1), 5, 60);

    expect(deployAsOwner($project)->status)->toBe(DeploymentStatus::Queued);
});

test('the slot frees when the oldest build that still holds the limit leaves the window', function (int $limit, int $expected): void {
    $project = buildRateProject($limit);
    seedBuilds($project, 1, 3000);
    seedBuilds($project, 1, 2000);
    seedBuilds($project, 1, 1000);

    try {
        deployAsOwner($project);
        $this->fail('The build rate limit did not hold.');
    } catch (BuildRateLimitExceededException $e) {
        expect($e->limit)->toBe($limit)
            ->and($e->current)->toBe(3)
            ->and($e->retryAfterSeconds)->toBe($expected);
    }
})->with([
    'at the limit, the oldest' => [3, 600],
    'limit lowered below what ran, the second oldest' => [2, 1600],
    'limit lowered to one, the newest' => [1, 2600],
]);

test('the wait reads in minutes rounded up, or in seconds under a minute', function (int $seconds, string $hint): void {
    expect((new BuildRateLimitExceededException(20, 20, $seconds))->retryHint())->toBe($hint);
})->with([
    [1, 'sekitar 1 detik lagi'],
    [59, 'sekitar 59 detik lagi'],
    [60, 'sekitar 1 menit lagi'],
    [61, 'sekitar 2 menit lagi'],
    [720, 'sekitar 12 menit lagi'],
    [3600, 'sekitar 60 menit lagi'],
]);

test('admins acting are not limited', function (): void {
    $project = buildRateProject(1);
    seedBuilds($project, 1, 60);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $adminBuild = deployAsOwner($project, $admin);
    expect($adminBuild->status)->toBe(DeploymentStatus::Queued);

    // The admin's build still counts for the members.
    $adminBuild->update(['status' => DeploymentStatus::Succeeded]);
    Deployment::query()->whereKeyNot($adminBuild->id)->delete();
    expect(fn () => deployAsOwner($project))->toThrow(BuildRateLimitExceededException::class);
});

test('an idempotent replay at the limit returns the existing deployment', function (): void {
    $project = buildRateProject(1);

    $first = $this->actingAs($project->user, 'web')
        ->withHeader('Idempotency-Key', 'build-rate-replay')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertCreated();

    $this->actingAs($project->user, 'web')
        ->withHeader('Idempotency-Key', 'build-rate-replay')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertCreated()
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(Deployment::query()->where('project_id', $project->id)->count())->toBe(1);
});

test('a refused deployment is recorded as a rejected limit signal', function (): void {
    $project = buildRateProject(1);
    seedBuilds($project, 1, 60);

    expect(fn () => deployAsOwner($project))->toThrow(BuildRateLimitExceededException::class);

    $signal = UsageSignalRecord::query()->where('signal_type', UsageSignalType::RejectedLimits)->sole();

    expect($signal->scope)->toBe('workspace')
        ->and($signal->tags)->toBe(['limit_name' => 'max_builds_per_hour']);
});

test('the profile value decides, and a profile without one follows the global setting', function (): void {
    $service = app(PilotRuntimeLimitService::class);
    config(['sakala.project.max_builds_per_hour' => 7]);

    expect($service->maxBuildsPerHourFor(LimitProfile::factory()->make(['max_builds_per_hour' => 5])))->toBe(5)
        ->and($service->maxBuildsPerHourFor(LimitProfile::factory()->make(['max_builds_per_hour' => null])))->toBe(7)
        ->and($service->maxBuildsPerHourFor(null))->toBe(7);
});

test('the global setting defaults to 20, empty means the default, and 0 means no limit', function (mixed $configured, ?int $expected): void {
    config(['sakala.project.max_builds_per_hour' => $configured]);

    expect(app(PilotRuntimeLimitService::class)->maxBuildsPerHourFor(null))->toBe($expected);
})->with([
    'unset' => [null, PilotRuntimeLimitService::DEFAULT_MAX_BUILDS_PER_HOUR],
    'empty' => ['', PilotRuntimeLimitService::DEFAULT_MAX_BUILDS_PER_HOUR],
    'set' => ['12', 12],
    'zero' => ['0', null],
]);

test('the config default is 20 builds per hour', function (): void {
    expect(PilotRuntimeLimitService::DEFAULT_MAX_BUILDS_PER_HOUR)->toBe(20)
        ->and(app(PilotRuntimeLimitService::class)->maxBuildsPerHourFor(null))->toBe(20);
});

test('workspaces without a profile, personal and team, follow the global setting', function (): void {
    config(['sakala.project.max_builds_per_hour' => 4]);
    $owner = User::factory()->create();
    $service = app(PilotRuntimeLimitService::class);

    expect($service->limitsFor($owner->personalWorkspace()->firstOrFail())->max_builds_per_hour)->toBe(4)
        ->and($service->limitsFor(teamWorkspace($owner))->max_builds_per_hour)->toBe(4);
});

test('a team without a profile is limited by the global setting', function (): void {
    config(['sakala.project.max_builds_per_hour' => 1]);
    $owner = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->inWorkspace(teamWorkspace($owner), $owner)->create(['branch' => 'main']);
    seedBuilds($project, 1, 60);

    expect(fn () => deployAsOwner($project))->toThrow(BuildRateLimitExceededException::class);
});

test('a global setting of 0 does not limit builds', function (): void {
    config(['sakala.project.max_builds_per_hour' => '0']);
    $owner = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($owner)->create(['branch' => 'main']);
    seedBuilds($project, 30, 60);

    expect(deployAsOwner($project)->status)->toBe(DeploymentStatus::Queued);
});

test('pilot limits and the admin workspace quota show the effective limit', function (): void {
    $owner = User::factory()->create(['role' => UserRole::User]);
    $workspace = $owner->personalWorkspace()->firstOrFail();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($owner, 'web')->getJson('/api/v1/app/pilot-limits')
        ->assertOk()
        ->assertJsonPath('data.quotas.max_builds_per_hour', 20);

    $this->actingAs($admin, 'web')->getJson("/api/v1/admin/workspaces/{$workspace->id}")
        ->assertOk()
        ->assertJsonPath('data.quota.limits.max_builds_per_hour', 20);

    $profile = LimitProfile::factory()->create(['max_builds_per_hour' => 5]);
    $workspace->update(['limit_profile_id' => $profile->id]);

    $this->actingAs($owner, 'web')->getJson('/api/v1/app/pilot-limits')
        ->assertJsonPath('data.quotas.max_builds_per_hour', 5);

    $this->actingAs($admin, 'web')->getJson("/api/v1/admin/workspaces/{$workspace->id}")
        ->assertJsonPath('data.quota.limits.max_builds_per_hour', 5);

    $this->actingAs($admin, 'web')->getJson("/api/v1/admin/limit-profiles/{$profile->id}")
        ->assertJsonPath('data.max_builds_per_hour', 5);

    $profile->update(['max_builds_per_hour' => null]);
    config(['sakala.project.max_builds_per_hour' => '0']);

    $this->actingAs($owner, 'web')->getJson('/api/v1/app/pilot-limits')
        ->assertJsonPath('data.quotas.max_builds_per_hour', null);

    $this->actingAs($admin, 'web')->getJson("/api/v1/admin/workspaces/{$workspace->id}")
        ->assertJsonPath('data.quota.limits.max_builds_per_hour', null);

    // The profile keeps its raw value: null follows the global setting.
    $this->actingAs($admin, 'web')->getJson("/api/v1/admin/limit-profiles/{$profile->id}")
        ->assertJsonPath('data.max_builds_per_hour', null);
});
