<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\WorkspaceRole;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Agent\AgentNodeSchedulerService;
use App\Services\Runtime\PilotRuntimeLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const GIB = 1024 * 1024 * 1024;

beforeEach(function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => '3e91b22a2e560a9f42b9d0921ca9b66c94462e5d', 'commit' => ['message' => 'feat: regions']],
        ], 200),
    ]);
});

/**
 * An online node that builds and runs projects. `$capacity` sets what its
 * heartbeat reported: `memory` (GiB available), `active`, and `maximum`
 * containers; missing keys are not reported.
 */
function placementNode(?string $region = null, array $capacity = [], array $overrides = [], bool $replicas = true, ?int $maximumReplicas = null): AgentNode
{
    $workloads = array_filter([
        AgentNode::ACTIVE_WORKLOADS_METADATA_KEY => $capacity['active'] ?? null,
        AgentNode::MAXIMUM_ACTIVE_WORKLOADS_METADATA_KEY => $capacity['maximum'] ?? null,
        AgentNode::MAXIMUM_REPLICAS_METADATA_KEY => $maximumReplicas,
    ], static fn (?int $value): bool => $value !== null);

    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'desired_state' => AgentNodeDesiredState::Active,
        'protocol_version' => 4,
        'last_seen_at' => now(),
        'capabilities' => [
            'docker-runtime', 'dockerfile-build', 'railpack-build', 'caddy-file-routing', 'project-inspection',
            ...($replicas ? [AgentNode::REPLICAS_CAPABILITY] : []),
        ],
        'metadata' => [
            'runtime' => [AgentNode::REGION_METADATA_KEY => $region],
            'resources' => array_filter([
                AgentNode::MEMORY_TOTAL_METADATA_KEY => isset($capacity['memory']) ? 16 * GIB : null,
                AgentNode::MEMORY_AVAILABLE_METADATA_KEY => isset($capacity['memory']) ? (int) ($capacity['memory'] * GIB) : null,
            ], static fn (?int $value): bool => $value !== null),
            'workloads' => $workloads,
        ],
        ...$overrides,
    ]);
}

/** A team project under a profile that allows replicas, without a workload yet. */
function placementProject(array $projectOverrides = [], array $profile = []): Project
{
    $owner = User::factory()->create();
    $team = teamWorkspace($owner, attributes: ['limit_profile_id' => LimitProfile::factory()->create([
        'max_active_containers' => 10,
        'max_active_deployments' => 5,
        'max_replicas' => 4,
        ...$profile,
    ])->id]);

    return Project::factory()->for($owner)->create(['workspace_id' => $team->id, 'branch' => 'main', ...$projectOverrides]);
}

function placeFor(Project $project, int $replicas = 1): ?AgentNode
{
    return app(AgentNodeSchedulerService::class)->selectNodeFor(AgentCommandType::DeployProject, $project->fresh(), $replicas);
}

function pinOpenDeploy(AgentNode $node, int $count): void
{
    foreach (range(1, $count) as $ignored) {
        AgentCommand::factory()->create([
            'agent_node_id' => $node->id,
            'type' => AgentCommandType::DeployProject,
            'status' => AgentCommandStatus::Pending,
            'expires_at' => null,
        ]);
    }
}

function ownerOf(Project $project): User
{
    return User::query()->findOrFail($project->user_id);
}

// ─── Region ──────────────────────────────────────────────────────────────────

test('a project with a region only goes to nodes of that region', function (): void {
    placementNode('jog', ['memory' => 12]);
    $jakarta = placementNode('jkt', ['memory' => 2]);
    $project = placementProject(['region' => 'jkt']);

    expect(placeFor($project)?->id)->toBe($jakarta->id);
});

test('a project without a region goes anywhere, a node without a region only takes those', function (): void {
    $unlabelled = placementNode(null, ['memory' => 8]);
    placementNode('jkt', ['memory' => 4]);

    expect(placeFor(placementProject())?->id)->toBe($unlabelled->id)
        ->and(placeFor(placementProject(['region' => 'jkt']))?->effectiveRegion())->toBe('jkt');
});

test('the workspace default region applies unless the project sets one', function (): void {
    $jakarta = placementNode('jkt');
    $jogja = placementNode('jog');

    expect(placeFor(placementProject(profile: ['default_region' => 'jog']))?->id)->toBe($jogja->id)
        ->and(placeFor(placementProject(['region' => 'jkt'], ['default_region' => 'jog']))?->id)->toBe($jakarta->id);

    $service = app(PilotRuntimeLimitService::class);
    expect($service->regionFor(placementProject(profile: ['default_region' => 'jog'])))->toBe('jog')
        ->and($service->regionFor(placementProject()))->toBeNull();
});

test('an admin region override places the node in that region', function (): void {
    $moved = placementNode('jkt', overrides: ['region_override' => 'jog']);
    placementNode('jkt');

    expect(placeFor(placementProject(['region' => 'jog']))?->id)->toBe($moved->id);
});

test('an offline node of the region is waited for, not replaced', function (): void {
    placementNode('jog');
    placementNode('jkt', overrides: ['last_seen_at' => now()->subHour()]);
    $project = placementProject(['region' => 'jkt']);

    $id = $this->actingAs(ownerOf($project), 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertCreated()
        ->json('data.id');

    expect(Deployment::query()->findOrFail($id)->agent_node_id)->toBeNull();
});

test('a region without any node refuses the deployment', function (): void {
    placementNode('jog');
    placementNode('jkt', overrides: ['desired_state' => AgentNodeDesiredState::Drained]);
    $project = placementProject(['region' => 'jkt']);

    $this->actingAs(ownerOf($project), 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertConflict()
        ->assertJsonPath('code', 'PROJECT_REGION_UNAVAILABLE')
        ->assertJsonPath('region', 'jkt');

    expect(Deployment::query()->where('project_id', $project->id)->exists())->toBeFalse();
});

// ─── Capacity ────────────────────────────────────────────────────────────────

test('the node with the most free memory wins', function (): void {
    placementNode(null, ['memory' => 2]);
    $roomy = placementNode(null, ['memory' => 10]);
    placementNode(null, ['memory' => 6]);

    expect(placeFor(placementProject())?->id)->toBe($roomy->id);
});

test('a node without free container slots comes after one with room', function (): void {
    placementNode(null, ['memory' => 12, 'active' => 5, 'maximum' => 5]);
    $withRoom = placementNode(null, ['memory' => 2, 'active' => 1, 'maximum' => 5]);

    expect(placeFor(placementProject())?->id)->toBe($withRoom->id);
});

test('slots must fit every replica', function (): void {
    placementNode(null, ['memory' => 12, 'active' => 3, 'maximum' => 5]);
    $fits = placementNode(null, ['memory' => 2, 'active' => 0, 'maximum' => 5]);

    expect(placeFor(placementProject(), 3)?->id)->toBe($fits->id);
});

test('deployments reserved since the last heartbeat count against a node', function (): void {
    $busy = placementNode(null, ['memory' => 3]);
    $other = placementNode(null, ['memory' => 2]);

    expect(placeFor(placementProject())?->id)->toBe($busy->id);

    // Each open deployment takes the default container memory (256 MB) and a slot.
    pinOpenDeploy($busy, 8);

    expect(placeFor(placementProject())?->id)->toBe($other->id);
});

test('a node that reports no memory comes after one that does', function (): void {
    placementNode(null);
    $reporting = placementNode(null, ['memory' => 1]);

    expect(placeFor(placementProject())?->id)->toBe($reporting->id);
});

test('without capacity reports the fewest reservations win, as before', function (): void {
    $busy = placementNode(null);
    $idle = placementNode(null);
    AgentCommand::factory()->create([
        'agent_node_id' => $busy->id,
        'type' => AgentCommandType::InspectProject,
        'status' => AgentCommandStatus::Running,
    ]);

    expect(placeFor(placementProject())?->id)->toBe($idle->id);
});

// ─── Stickiness and replicas ─────────────────────────────────────────────────

test('a project stays on the node that holds its workload, whatever region or capacity', function (): void {
    $holding = placementNode('jkt', ['memory' => 1, 'active' => 5, 'maximum' => 5]);
    placementNode('jog', ['memory' => 12]);
    $project = placementProject(['region' => 'jog']);
    Deployment::factory()->for($project)->create([
        'agent_node_id' => $holding->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
    ]);

    expect(placeFor($project)?->id)->toBe($holding->id);

    // Its node offline: the command waits instead of moving.
    $holding->update(['last_seen_at' => now()->subHour()]);
    expect(placeFor($project))->toBeNull();

    // Deploying still works and waits for the node, even though the region differs.
    $this->actingAs(ownerOf($project), 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertCreated();
});

test('replica support and region combine', function (): void {
    placementNode('jkt', ['memory' => 12], replicas: false);
    placementNode('jog', ['memory' => 12], maximumReplicas: 4);
    $capable = placementNode('jkt', ['memory' => 2], maximumReplicas: 4);
    $project = placementProject(['region' => 'jkt', 'replicas' => 3]);

    expect(placeFor($project, 3)?->id)->toBe($capable->id)
        ->and(placeFor($project, 1)?->id)->not->toBe($capable->id);
});

test('a region whose nodes cannot run the replicas refuses them', function (): void {
    placementNode('jkt', replicas: false);
    placementNode('jog', maximumReplicas: 4);
    $project = placementProject(['region' => 'jkt', 'replicas' => 3]);

    $this->actingAs(ownerOf($project), 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertConflict()
        ->assertJsonPath('code', 'PROJECT_REPLICAS_UNSUPPORTED');
});

test('all replicas go to one node', function (): void {
    placementNode(null, ['memory' => 8], maximumReplicas: 4);
    placementNode(null, ['memory' => 8], maximumReplicas: 4);
    $project = placementProject(['replicas' => 3]);

    $placement = app(AgentNodeSchedulerService::class)->placementFor(AgentCommandType::DeployProject, $project, 3);

    expect($placement->assignments)->toHaveCount(1)
        ->and($placement->assignments[0]['replicas'])->toBe(3);
});

// ─── Regions endpoint and settings ───────────────────────────────────────────

test('the regions endpoint lists the distinct regions of nodes allowed work', function (): void {
    placementNode('jog');
    placementNode('jkt');
    placementNode('jkt', overrides: ['last_seen_at' => now()->subDay()]);
    placementNode(null);
    placementNode('sby', overrides: ['auth_status' => AgentAuthStatus::Revoked]);
    placementNode('mdn', overrides: ['desired_state' => AgentNodeDesiredState::Drained]);
    placementNode('jkt', overrides: ['region_override' => 'bdg']);

    $this->actingAs(User::factory()->create(), 'web')
        ->getJson('/api/v1/app/regions')
        ->assertOk()
        ->assertExactJson(['data' => [['slug' => 'bdg'], ['slug' => 'jkt'], ['slug' => 'jog']]]);
});

test('the regions endpoint needs a session', function (): void {
    $this->getJson('/api/v1/app/regions')->assertUnauthorized();
});

test('a member sets and clears the project region among the available ones', function (): void {
    placementNode('jkt');
    $project = placementProject();
    $owner = ownerOf($project);

    $this->actingAs($owner, 'web')
        ->patchJson("/api/v1/app/projects/{$project->id}", ['region' => 'jkt'])
        ->assertOk()
        ->assertJsonPath('data.region', 'jkt')
        ->assertJsonPath('data.redeploy_required', false);

    $this->actingAs($owner, 'web')
        ->patchJson("/api/v1/app/projects/{$project->id}", ['region' => null])
        ->assertOk()
        ->assertJsonPath('data.region', null);
});

test('a region without nodes or a malformed one is rejected', function (mixed $region): void {
    placementNode('jkt');
    $project = placementProject();

    $this->actingAs(ownerOf($project), 'web')
        ->patchJson("/api/v1/app/projects/{$project->id}", ['region' => $region])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('region');

    expect($project->fresh()->region)->toBeNull();
})->with([
    'no node there' => ['jog'],
    'uppercase' => ['JKT'],
    'not a string' => [12],
]);

test('a viewer cannot change the region', function (): void {
    placementNode('jkt');
    $project = placementProject();
    $viewer = User::factory()->create();
    addWorkspaceMember(Workspace::query()->findOrFail($project->workspace_id), $viewer, WorkspaceRole::Viewer);

    $this->actingAs($viewer, 'web')
        ->patchJson("/api/v1/app/projects/{$project->id}", ['region' => 'jkt'])
        ->assertForbidden();
});

test('an admin sets a profile default region', function (): void {
    $profile = LimitProfile::factory()->create();

    $this->actingAs(adminApiAdmin(), 'web')
        ->patchJson("/api/v1/admin/limit-profiles/{$profile->id}", ['default_region' => 'jkt', 'reason' => 'Tim di Jakarta'])
        ->assertOk()
        ->assertJsonPath('data.default_region', 'jkt');

    $this->actingAs(adminApiAdmin(), 'web')
        ->patchJson("/api/v1/admin/limit-profiles/{$profile->id}", ['default_region' => 'Jakarta Raya', 'reason' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('default_region');
});
