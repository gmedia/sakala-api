<?php

declare(strict_types=1);

use App\Actions\Agent\AssignPendingCommandsAction;
use App\Actions\Project\SleepIdleProjectsAction;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\RuntimeStatus;
use App\Enums\WorkspaceRole;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Runtime\PilotRuntimeLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => '3e91b22a2e560a9f42b9d0921ca9b66c94462e5d', 'commit' => ['message' => 'feat: replicas']],
        ], 200),
    ]);
});

/** A node that builds, runs, and sleeps projects, and runs replicas unless told otherwise. */
function replicaNode(bool $replicas = true, array $overrides = []): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'protocol_version' => 4,
        'desired_state' => AgentNodeDesiredState::Active,
        'last_seen_at' => now(),
        'capabilities' => [
            'docker-runtime',
            'dockerfile-build',
            'railpack-build',
            'caddy-file-routing',
            SleepIdleProjectsAction::CAPABILITY,
            ...($replicas ? [AgentNode::REPLICAS_CAPABILITY] : []),
        ],
        'metadata' => ['workloads' => ['traffic' => []]],
        ...$overrides,
    ]);
}

/**
 * A team workspace under a profile with the given limits, and its owner.
 *
 * @return array{User, Workspace}
 */
function replicaWorkspace(array $profile = []): array
{
    $owner = User::factory()->create();
    $team = teamWorkspace($owner, attributes: ['limit_profile_id' => LimitProfile::factory()->create([
        'max_active_containers' => 10,
        'max_active_deployments' => 5,
        'max_replicas' => 4,
        ...$profile,
    ])->id]);

    return [$owner, $team];
}

/**
 * A project of the workspace serving `$replicas` containers on the node.
 *
 * @return array{Project, Deployment}
 */
function servingWithReplicas(AgentNode $node, Workspace $team, User $owner, int $replicas, array $projectOverrides = []): array
{
    return idleSleepProject(
        $node,
        ['workspace_id' => $team->id, 'branch' => 'main', 'replicas' => $replicas, ...$projectOverrides],
        ['replicas' => $replicas],
        owner: $owner,
    );
}

function deployReplicas(object $test, User $user, Project $project): TestResponse
{
    return $test->actingAs($user, 'web')->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main']);
}

function deployCommandOf(string $deploymentId): AgentCommand
{
    return AgentCommand::query()
        ->where('deployment_id', $deploymentId)
        ->where('type', AgentCommandType::DeployProject)
        ->sole();
}

// ─── Effective maximum ───────────────────────────────────────────────────────

test('the effective maximum comes from the profile, and one without it', function (?int $profileValue, int $expected): void {
    $service = app(PilotRuntimeLimitService::class);
    $profile = LimitProfile::factory()->create(['max_replicas' => $profileValue]);
    $owner = User::factory()->create();

    expect($service->maxReplicasFor($profile))->toBe($expected)
        ->and($service->limitsFor(Workspace::factory()->withProfile($profile)->create())->max_replicas)->toBe($expected)
        ->and($service->maxReplicasFor(null))->toBe(Project::DEFAULT_REPLICAS)
        ->and($service->limitsFor($owner->personalWorkspace()->firstOrFail())->max_replicas)->toBe(Project::DEFAULT_REPLICAS)
        ->and($service->limitsFor(teamWorkspace($owner))->max_replicas)->toBe(Project::DEFAULT_REPLICAS);
})->with([
    'not set' => [null, 1],
    'allowed' => [3, 3],
]);

// ─── Settings ────────────────────────────────────────────────────────────────

test('a member changes replicas within the workspace maximum, from the next deployment', function (): void {
    $node = replicaNode();
    [$owner, $team] = replicaWorkspace(['max_replicas' => 3]);
    $member = User::factory()->create();
    addWorkspaceMember($team, $member, WorkspaceRole::Member);
    [$project] = servingWithReplicas($node, $team, $owner, 1);

    $this->actingAs($member, 'web')
        ->patchJson("/api/v1/app/projects/{$project->id}", ['replicas' => 3])
        ->assertOk()
        ->assertJsonPath('data.replicas', 3)
        ->assertJsonPath('data.max_replicas', 3)
        // The serving deployment still runs one until the next deployment.
        ->assertJsonPath('data.serving_replicas', 1)
        ->assertJsonPath('data.redeploy_required', true);

    expect($project->fresh()->replicas)->toBe(3);
});

test('replicas outside one and the workspace maximum are refused', function (mixed $value, ?int $profileMax): void {
    $node = replicaNode();
    [$owner, $team] = replicaWorkspace(['max_replicas' => $profileMax]);
    [$project] = servingWithReplicas($node, $team, $owner, 1);

    $this->actingAs($owner, 'web')
        ->patchJson("/api/v1/app/projects/{$project->id}", ['replicas' => $value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['replicas']);

    expect($project->fresh()->replicas)->toBe(1);
})->with([
    'zero' => [0, 3],
    'above the profile' => [4, 3],
    'more than one without a profile value' => [2, null],
    'fraction' => [1.5, 3],
    'null' => [null, 3],
]);

test('viewers cannot change replicas', function (): void {
    $node = replicaNode();
    [$owner, $team] = replicaWorkspace();
    $viewer = User::factory()->create();
    addWorkspaceMember($team, $viewer, WorkspaceRole::Viewer);
    [$project] = servingWithReplicas($node, $team, $owner, 1);

    $this->actingAs($viewer, 'web')
        ->patchJson("/api/v1/app/projects/{$project->id}", ['replicas' => 2])
        ->assertForbidden();
});

test('the project exposes readiness of the serving deployment only', function (): void {
    $node = replicaNode();
    [$owner, $team] = replicaWorkspace();
    [$project, $deployment] = servingWithReplicas($node, $team, $owner, 3);

    // Not reported yet: the count asked for, readiness unknown.
    $this->actingAs($owner, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.replicas', 3)
        ->assertJsonPath('data.max_replicas', 4)
        ->assertJsonPath('data.serving_replicas', 3)
        ->assertJsonPath('data.replicas_ready', null);

    $deployment->update(['replicas_desired' => 3, 'replicas_ready' => 2]);

    $this->actingAs($owner, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertJsonPath('data.serving_replicas', 3)
        ->assertJsonPath('data.replicas_ready', 2);

    $project->update(['runtime_status' => RuntimeStatus::Sleeping]);

    $this->actingAs($owner, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertJsonPath('data.serving_replicas', null)
        ->assertJsonPath('data.replicas_ready', null);
});

// ─── Deploy payload ──────────────────────────────────────────────────────────

test('a node with the capability receives replicas above one', function (): void {
    $node = replicaNode();
    [$owner, $team] = replicaWorkspace();
    [$project] = servingWithReplicas($node, $team, $owner, 1);
    $project->update(['replicas' => 3]);

    $id = deployReplicas($this, $owner, $project)->assertCreated()->json('data.id');
    $command = deployCommandOf($id);

    expect($command->agent_node_id)->toBe($node->id)
        ->and($command->payload['replicas'])->toBe(3)
        ->and($command->requestedReplicas())->toBe(3)
        ->and(Deployment::query()->findOrFail($id)->replicas)->toBe(3);
});

test('one replica is never sent, whatever the node', function (bool $capable): void {
    $node = replicaNode($capable);
    [$owner, $team] = replicaWorkspace();
    [$project] = servingWithReplicas($node, $team, $owner, 1);

    $id = deployReplicas($this, $owner, $project)->assertCreated()->json('data.id');

    expect(deployCommandOf($id)->payload)->not->toHaveKey('replicas')
        ->and(Deployment::query()->findOrFail($id)->replicas)->toBe(1);
})->with(['capable node' => [true], 'older node' => [false]]);

test('a node without the capability refuses a project with replicas up front', function (): void {
    $node = replicaNode(replicas: false);
    [$owner, $team] = replicaWorkspace();
    [$project] = servingWithReplicas($node, $team, $owner, 1);
    $project->update(['replicas' => 2]);

    deployReplicas($this, $owner, $project)
        ->assertConflict()
        ->assertJsonPath('code', 'PROJECT_REPLICAS_UNSUPPORTED')
        ->assertJsonPath('replicas', 2);

    expect(Deployment::query()->where('project_id', $project->id)->count())->toBe(1)
        ->and(AgentCommand::query()->where('type', AgentCommandType::DeployProject)->exists())->toBeFalse();
});

test('a lowered profile maximum applies from the next deployment', function (): void {
    $node = replicaNode();
    [$owner, $team] = replicaWorkspace(['max_replicas' => 2]);
    [$project] = servingWithReplicas($node, $team, $owner, 1, ['replicas' => 4]);

    $id = deployReplicas($this, $owner, $project)->assertCreated()->json('data.id');

    expect(deployCommandOf($id)->payload['replicas'])->toBe(2)
        ->and(Deployment::query()->findOrFail($id)->replicas)->toBe(2)
        ->and($project->fresh()->replicas)->toBe(4);
});

test('without a node, a deployment with replicas waits for one that runs them all', function (): void {
    [$owner, $team] = replicaWorkspace();
    $project = Project::factory()->inWorkspace($team, $owner)->create(['branch' => 'main', 'replicas' => 3]);

    $id = deployReplicas($this, $owner, $project)->assertCreated()->json('data.id');
    $command = deployCommandOf($id);

    expect($command->agent_node_id)->toBeNull()
        ->and($command->payload['replicas'])->toBe(3);

    $older = replicaNode(replicas: false);

    expect(app(AssignPendingCommandsAction::class)->handle())->toBe(0)
        ->and($command->fresh()->agent_node_id)->toBeNull();

    // Once the older node is gone, a capable one takes it.
    $older->update(['status' => AgentNodeStatus::Offline]);
    $capable = replicaNode();

    expect(app(AssignPendingCommandsAction::class)->handle())->toBe(1)
        ->and($command->fresh()->agent_node_id)->toBe($capable->id)
        ->and(Deployment::query()->findOrFail($id)->agent_node_id)->toBe($capable->id);
});

// ─── Workspace container limit ───────────────────────────────────────────────

test('running containers count the replicas of serving projects, not sleeping ones', function (): void {
    $node = replicaNode();
    [$owner, $team] = replicaWorkspace(['max_active_containers' => 9, 'max_replicas' => 3]);
    servingWithReplicas($node, $team, $owner, 3);
    servingWithReplicas($node, $team, $owner, 2);
    servingWithReplicas($node, $team, $owner, 3, ['runtime_status' => RuntimeStatus::Sleeping]);

    $this->actingAs($owner, 'web')
        ->getJson("/api/v1/app/pilot-limits?workspace_id={$team->id}")
        ->assertOk()
        ->assertJsonPath('data.quotas.max_active_containers', 9)
        ->assertJsonPath('data.quotas.current_active_containers_count', 5)
        ->assertJsonPath('data.quotas.max_replicas', 3);

    $this->actingAs(adminApiAdmin(), 'web')
        ->getJson("/api/v1/admin/workspaces/{$team->id}")
        ->assertOk()
        ->assertJsonPath('data.quota.limits.max_replicas', 3)
        ->assertJsonPath('data.quota.usage.active_containers', 5);
});

test('a deployment in progress counts the replicas it will run', function (): void {
    $node = replicaNode();
    [$owner, $team] = replicaWorkspace(['max_active_containers' => 10]);
    [$serving] = servingWithReplicas($node, $team, $owner, 1);
    Deployment::factory()->for($serving)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Building,
        'sequence' => 2,
        'replicas' => 3,
    ]);
    $new = Project::factory()->inWorkspace($team, $owner)->create();
    Deployment::factory()->for($new)->create(['status' => DeploymentStatus::Queued, 'sequence' => 1, 'replicas' => 2]);

    $quota = app(PilotRuntimeLimitService::class)->getPilotQuotaLimits($team);

    expect($quota->current_active_containers_count)->toBe(5);
});

test('deploying a project that does not serve needs a slot for every replica', function (int $limit, bool $allowed): void {
    $node = replicaNode();
    [$owner, $team] = replicaWorkspace(['max_active_containers' => $limit]);
    servingWithReplicas($node, $team, $owner, 2);
    $project = Project::factory()->inWorkspace($team, $owner)->create(['branch' => 'main', 'replicas' => 3]);

    $response = deployReplicas($this, $owner, $project);

    if ($allowed) {
        $response->assertCreated();
    } else {
        $response->assertUnprocessable()
            ->assertJsonPath('code', 'ACTIVE_CONTAINER_LIMIT_EXCEEDED')
            ->assertJsonPath('limit', $limit)
            ->assertJsonPath('current', 2)
            ->assertJsonPath('requested', 3);

        expect($response->json('message'))->toContain('3 replika');
    }
})->with([
    'two short' => [4, false],
    'exactly enough' => [5, true],
]);

test('redeploying with more replicas needs a slot only for the new ones', function (int $limit, bool $allowed): void {
    $node = replicaNode();
    [$owner, $team] = replicaWorkspace(['max_active_containers' => $limit]);
    servingWithReplicas($node, $team, $owner, 1);
    [$project] = servingWithReplicas($node, $team, $owner, 1);
    $project->update(['replicas' => 3]);

    $response = deployReplicas($this, $owner, $project);

    if ($allowed) {
        $response->assertCreated();
    } else {
        $response->assertUnprocessable()
            ->assertJsonPath('code', 'ACTIVE_CONTAINER_LIMIT_EXCEEDED')
            ->assertJsonPath('current', 2)
            ->assertJsonPath('requested', 3);
    }
})->with([
    // Other project 1 + this one going from 1 to 3 = 4.
    'one short' => [3, false],
    'exactly enough' => [4, true],
]);

test('redeploying with the same or fewer replicas never needs a slot', function (int $next): void {
    $node = replicaNode();
    // The workspace is already over a limit an admin lowered.
    [$owner, $team] = replicaWorkspace(['max_active_containers' => 2]);
    servingWithReplicas($node, $team, $owner, 2);
    [$project] = servingWithReplicas($node, $team, $owner, 3, ['replicas' => $next]);

    deployReplicas($this, $owner, $project)->assertCreated();
})->with(['same' => [3], 'fewer' => [2]]);

test('waking needs a slot for every replica of the sleeping deployment', function (int $limit, bool $allowed): void {
    $node = replicaNode();
    [$owner, $team] = replicaWorkspace(['max_active_containers' => $limit]);
    servingWithReplicas($node, $team, $owner, 2);
    // The setting changed while asleep; the wake starts what the node keeps.
    [$project] = servingWithReplicas($node, $team, $owner, 3, ['runtime_status' => RuntimeStatus::Sleeping, 'replicas' => 1]);

    $response = $this->actingAs($owner, 'web')->postJson("/api/v1/app/projects/{$project->id}/wake");

    if ($allowed) {
        $response->assertStatus(202);
    } else {
        $response->assertConflict()
            ->assertJsonPath('code', 'PROJECT_WAKE_LIMIT_EXCEEDED')
            ->assertJsonPath('limit', $limit)
            ->assertJsonPath('current', 2)
            ->assertJsonPath('requested', 3);
    }

    expect(AgentCommand::query()->where('type', AgentCommandType::WakeProject)->count())->toBe($allowed ? 1 : 0);
})->with([
    'two short' => [4, false],
    'exactly enough' => [5, true],
]);

test('the public wake keeps the counts out with replicas too', function (): void {
    $node = replicaNode();
    [$owner, $team] = replicaWorkspace(['max_active_containers' => 3]);
    [$project] = servingWithReplicas($node, $team, $owner, 4, ['runtime_status' => RuntimeStatus::Sleeping]);

    $this->postJson("/api/v1/public/projects/{$project->slug}/wake")
        ->assertConflict()
        ->assertJsonPath('code', 'PROJECT_WAKE_LIMIT_EXCEEDED')
        ->assertJsonMissingPath('limit')
        ->assertJsonMissingPath('requested');
});
