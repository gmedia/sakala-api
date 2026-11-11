<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\RuntimeStatus;
use App\Enums\UserRole;
use App\Enums\WorkspaceRole;
use App\Models\Deployment;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Models\User;
use App\Services\Runtime\PilotRuntimeLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => '0123456789abcdef0123456789abcdef01234567', 'commit' => ['message' => 'feat: initial']],
        ], 200),
        '*' => Http::response([], 404),
    ]);
});

function createProjectIn(object $test, User $user, ?string $workspaceId = null): TestResponse
{
    return $test->actingAs($user, 'web')->postJson('/api/v1/app/projects', array_filter([
        'name' => 'Proyek '.Str::random(6),
        'repository_url' => 'https://github.com/gmedia/'.Str::lower(Str::random(8)),
        'branch' => 'main',
        'workspace_id' => $workspaceId,
    ]));
}

test('a personal workspace without a profile keeps exactly the pilot limits', function (): void {
    $user = User::factory()->create();
    $limits = app(PilotRuntimeLimitService::class)->limitsFor($user->personalWorkspace()->firstOrFail());

    expect($limits->max_projects)->toBe(3)
        ->and($limits->max_active_deployments)->toBe(2)
        ->and($limits->max_active_containers)->toBe(3)
        ->and($limits->max_memory_mb)->toBe(512)
        ->and($limits->max_cpu_millis)->toBe(1000)
        ->and($limits->max_builds_per_hour)->toBeNull()
        ->and($limits->profile)->toBeNull();

    $this->actingAs($user, 'web')->getJson('/api/v1/app/pilot-limits')
        ->assertOk()
        ->assertJsonPath('data.workspace_id', $user->personalWorkspace()->value('id'))
        ->assertJsonPath('data.limit_profile', null)
        ->assertJsonPath('data.quotas.max_projects_per_user', 3)
        ->assertJsonPath('data.quotas.max_active_deployments_per_user', 2)
        ->assertJsonPath('data.quotas.max_active_containers', 3)
        ->assertJsonPath('data.runtime_defaults.memory_mb', 256)
        ->assertJsonPath('data.runtime_maximums.memory_mb', 512)
        ->assertJsonPath('data.runtime_maximums.cpu_millis', 1000);
});

test('a team without a profile follows the team config and falls back to the pilot values', function (): void {
    $team = teamWorkspace(User::factory()->create());
    $service = app(PilotRuntimeLimitService::class);

    expect($service->limitsFor($team)->max_projects)->toBe(3);

    config(['sakala.workspaces.team_limits.max_projects' => 10]);

    expect($service->limitsFor($team)->max_projects)->toBe(10)
        ->and($service->limitsFor($team)->max_active_containers)->toBe(10)
        ->and($service->limitsFor($team)->max_active_deployments)->toBe(2);
});

test('team projects count against the team, not against the members personal quota', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $profile = LimitProfile::factory()->create(['max_projects' => 2]);
    $team = teamWorkspace($owner, [[$member, WorkspaceRole::Member]], ['limit_profile_id' => $profile->id]);
    Project::factory()->count(3)->for($member)->create();

    createProjectIn($this, $member, $team->id)->assertCreated();
    createProjectIn($this, $owner, $team->id)->assertCreated();

    createProjectIn($this, $member, $team->id)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'PROJECT_LIMIT_EXCEEDED')
        ->assertJsonPath('limit', 2)
        ->assertJsonPath('current', 2);

    // The member's personal workspace is full on its own terms.
    createProjectIn($this, $member)->assertUnprocessable()->assertJsonPath('limit', 3);
    createProjectIn($this, $owner)->assertCreated();

    $this->actingAs($member, 'web')->getJson("/api/v1/app/pilot-limits?workspace_id={$team->id}")
        ->assertOk()
        ->assertJsonPath('data.limit_profile.id', $profile->id)
        ->assertJsonPath('data.quotas.max_projects_per_user', 2)
        ->assertJsonPath('data.quotas.current_projects_count', 2);

    $this->actingAs(User::factory()->create(), 'web')->getJson("/api/v1/app/pilot-limits?workspace_id={$team->id}")->assertForbidden();
});

test('concurrent deployments are limited per workspace', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $profile = LimitProfile::factory()->create(['max_active_deployments' => 1, 'max_active_containers' => 10]);
    $team = teamWorkspace($owner, [[$member, WorkspaceRole::Member]], ['limit_profile_id' => $profile->id]);
    $first = Project::factory()->inWorkspace($team)->create(['branch' => 'main']);
    $second = Project::factory()->inWorkspace($team)->create(['branch' => 'main']);

    $this->actingAs($owner, 'web')->postJson("/api/v1/app/projects/{$first->id}/deployments", ['branch' => 'main'])->assertCreated();

    $this->actingAs($member, 'web')->postJson("/api/v1/app/projects/{$second->id}/deployments", ['branch' => 'main'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'ACTIVE_DEPLOYMENT_LIMIT_EXCEEDED')
        ->assertJsonPath('scope', 'workspace')
        ->assertJsonPath('limit', 1);
});

test('running containers are limited per workspace, and redeploying a serving project is not', function (): void {
    $owner = User::factory()->create();
    $profile = LimitProfile::factory()->create(['max_active_containers' => 1, 'max_active_deployments' => 5]);
    $team = teamWorkspace($owner, attributes: ['limit_profile_id' => $profile->id]);
    $serving = Project::factory()->inWorkspace($team)->create(['branch' => 'main', 'runtime_status' => RuntimeStatus::Running]);
    $active = Deployment::factory()->for($serving)->for($owner, 'requester')->create(['status' => DeploymentStatus::Succeeded]);
    $serving->update(['active_deployment_id' => $active->id]);
    $idle = Project::factory()->inWorkspace($team)->create(['branch' => 'main']);

    $this->actingAs($owner, 'web')->postJson("/api/v1/app/projects/{$idle->id}/deployments", ['branch' => 'main'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'ACTIVE_CONTAINER_LIMIT_EXCEEDED')
        ->assertJsonPath('limit', 1)
        ->assertJsonPath('current', 1);

    $this->actingAs($owner, 'web')->postJson("/api/v1/app/projects/{$serving->id}/deployments", ['branch' => 'main'])->assertCreated();
});

test('a project being deployed holds a container slot', function (): void {
    $owner = User::factory()->create();
    $profile = LimitProfile::factory()->create(['max_active_containers' => 1, 'max_active_deployments' => 5]);
    $team = teamWorkspace($owner, attributes: ['limit_profile_id' => $profile->id]);
    $a = Project::factory()->inWorkspace($team)->create(['branch' => 'main']);
    $b = Project::factory()->inWorkspace($team)->create(['branch' => 'main']);

    $this->actingAs($owner, 'web')->postJson("/api/v1/app/projects/{$a->id}/deployments", ['branch' => 'main'])->assertCreated();
    $this->actingAs($owner, 'web')->postJson("/api/v1/app/projects/{$b->id}/deployments", ['branch' => 'main'])
        ->assertUnprocessable()->assertJsonPath('code', 'ACTIVE_CONTAINER_LIMIT_EXCEEDED');
});

test('per-project memory and CPU caps come from the profile, and the default never exceeds the cap', function (): void {
    $owner = User::factory()->create();
    $profile = LimitProfile::factory()->create(['max_memory_mb' => 128, 'max_cpu_millis' => 250]);
    $team = teamWorkspace($owner, attributes: ['limit_profile_id' => $profile->id]);
    $project = Project::factory()->inWorkspace($team)->create(['branch' => 'main']);

    $this->actingAs($owner, 'web')->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main', 'resources' => ['memory_mb' => 256]])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'RESOURCE_LIMIT_EXCEEDED')
        ->assertJsonPath('maximum', 128);

    $deploymentId = $this->actingAs($owner, 'web')->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertCreated()->json('data.id');

    $deployment = Deployment::query()->findOrFail($deploymentId);
    expect($deployment->effective_resources['resources']['memory_mb'])->toBe(128)
        ->and($deployment->effective_resources['resources']['cpu_millis'])->toBe(250);

    $this->actingAs($owner, 'web')->getJson("/api/v1/app/pilot-limits?workspace_id={$team->id}")
        ->assertJsonPath('data.runtime_defaults.memory_mb', 128)
        ->assertJsonPath('data.runtime_maximums.cpu_millis', 250);
});

test('admins are not limited when creating projects, as before', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    Project::factory()->count(3)->for($admin)->create();

    createProjectIn($this, $admin)->assertCreated();
});

test('an empty limit variable falls back instead of meaning zero', function (): void {
    config([
        'sakala.workspaces.team_limits.max_projects' => '',
        'sakala.pilot_limits.max_active_containers_per_workspace' => '',
    ]);

    $service = app(PilotRuntimeLimitService::class);

    expect($service->limitsFor(teamWorkspace(User::factory()->create()))->max_projects)->toBe(3)
        ->and($service->limitsFor(User::factory()->create()->personalWorkspace()->firstOrFail())->max_active_containers)->toBe(3);
});
