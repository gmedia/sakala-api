<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectInspectionStatus;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Enums\UserRole;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const INSPECTION_SHA = '0123456789abcdef0123456789abcdef01234567';

function inspectionNode(string $token = 'inspection-token', array $overrides = []): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'protocol_version' => 4,
        'last_seen_at' => now(),
        'capabilities' => ['docker-runtime', 'project-inspection', 'dockerfile-build', 'railpack-build'],
        'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
        ...$overrides,
    ]);
}

function inspectionHeaders(AgentNode $node, string $token = 'inspection-token'): array
{
    return ['Authorization' => 'Bearer '.$token, 'X-Agent-Id' => $node->agent_id];
}

function fakeInspectionCommit(string $sha = INSPECTION_SHA): void
{
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => $sha, 'commit' => ['message' => 'feat: initial']],
        ], 200),
    ]);
}

function succeededInspection(array $overrides = []): array
{
    return [
        'inspection_status' => ProjectInspectionStatus::Succeeded,
        'inspection' => [
            'repository_url' => 'https://github.com/example/app',
            'commit_sha' => INSPECTION_SHA,
            'dockerfile_found' => false,
            'env_example_found' => false,
            'compose_found' => false,
            'manifests' => ['package.json'],
            'package_manager' => 'npm',
            'railpack' => [
                'detectedProviders' => ['node'],
                'metadata' => ['nodeSPAFramework' => 'vite', 'secretish' => 'internal'],
                'plan' => ['deploy' => ['startCmd' => 'internal command']],
            ],
            ...$overrides,
        ],
    ];
}

// ─── Re-inspect ──────────────────────────────────────────────────────────────

test('the owner can ask for a new inspection', function (): void {
    fakeInspectionCommit();
    inspectionNode();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['branch' => 'main']);

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.app.projects.inspection', $project))
        ->assertAccepted()
        ->assertJsonPath('data.preview_status', 'pending');

    expect(AgentCommand::query()->where('project_id', $project->id)->where('type', AgentCommandType::InspectProject)->count())->toBe(1);
});

test('a new inspection reuses one running for the same commit', function (): void {
    fakeInspectionCommit();
    inspectionNode();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['branch' => 'main']);

    $this->actingAs($user, 'web');
    $this->postJson(route('api.v1.app.projects.inspection', $project))->assertAccepted();
    $this->postJson(route('api.v1.app.projects.inspection', $project))->assertAccepted();

    expect(AgentCommand::query()->where('project_id', $project->id)->count())->toBe(1);
});

test('an inspection that failed can be tried again on the same commit', function (AgentCommandStatus $status): void {
    fakeInspectionCommit();
    inspectionNode();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['branch' => 'main', 'inspection_status' => ProjectInspectionStatus::Failed]);
    AgentCommand::factory()->create([
        'project_id' => $project->id,
        'type' => AgentCommandType::InspectProject,
        'status' => $status,
        'idempotency_key' => "inspect:{$project->id}:".INSPECTION_SHA,
    ]);

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.app.projects.inspection', $project))
        ->assertAccepted()
        ->assertJsonPath('data.preview_status', 'pending');

    expect(AgentCommand::query()->where('project_id', $project->id)->where('status', AgentCommandStatus::Pending)->count())->toBe(1);

    // Asking again reuses the new attempt instead of piling up.
    $this->postJson(route('api.v1.app.projects.inspection', $project))->assertAccepted();

    expect(AgentCommand::query()->where('project_id', $project->id)->count())->toBe(2);
})->with([AgentCommandStatus::Failed, AgentCommandStatus::Expired, AgentCommandStatus::Cancelled]);

test('a finished inspection of the same commit is kept', function (): void {
    fakeInspectionCommit();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['branch' => 'main', ...succeededInspection()]);
    AgentCommand::factory()->create([
        'project_id' => $project->id,
        'type' => AgentCommandType::InspectProject,
        'status' => AgentCommandStatus::Succeeded,
        'idempotency_key' => "inspect:{$project->id}:".INSPECTION_SHA,
    ]);

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.app.projects.inspection', $project))
        ->assertAccepted()
        ->assertJsonPath('data.preview_status', 'succeeded');

    expect(AgentCommand::query()->where('project_id', $project->id)->count())->toBe(1);
});

test('re-inspecting is limited to the owner, throttled, and refused while suspended', function (): void {
    fakeInspectionCommit();
    config()->set('sakala.rate_limits.inspection', 2);
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['branch' => 'main']);
    $suspended = Project::factory()->for($user)->create(['status' => ProjectStatus::Suspended]);

    $this->postJson(route('api.v1.app.projects.inspection', $project))->assertUnauthorized();

    $this->actingAs(User::factory()->create(), 'web')
        ->postJson(route('api.v1.app.projects.inspection', $project))
        ->assertForbidden();

    app('auth')->forgetGuards();

    $this->actingAs($user, 'web');
    $this->postJson(route('api.v1.app.projects.inspection', $suspended))
        ->assertConflict()
        ->assertJsonPath('code', 'PROJECT_SUSPENDED');
    $this->postJson(route('api.v1.app.projects.inspection', $project))->assertAccepted();
    $this->postJson(route('api.v1.app.projects.inspection', $project))->assertTooManyRequests();
});

// ─── Stack summary ───────────────────────────────────────────────────────────

test('the stack summary exposes only stable derived fields', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(succeededInspection());

    $response = $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.stack', [
            'builder' => 'railpack',
            'provider' => 'node',
            'framework' => 'vite',
            'package_manager' => 'npm',
        ]);

    expect($response->getContent())->not->toContain('internal command')
        ->not->toContain('detectedProviders')
        ->not->toContain('secretish');

    $this->getJson('/api/v1/app/projects')
        ->assertOk()
        ->assertJsonPath('data.0.stack.provider', 'node');
});

test('a Dockerfile wins the builder, and unknown values stay null', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(succeededInspection([
        'dockerfile_found' => true,
        'package_manager' => null,
        'railpack' => null,
    ]));

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertJsonPath('data.stack', [
            'builder' => 'dockerfile',
            'provider' => null,
            'framework' => null,
            'package_manager' => null,
        ]);
});

test('odd agent output never reaches the console as a label', function (mixed $provider): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(succeededInspection([
        'railpack' => ['detectedProviders' => [$provider], 'metadata' => ['framework' => ['nested']]],
    ]));

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertJsonPath('data.stack.provider', null)
        ->assertJsonPath('data.stack.framework', null);
})->with(['<script>alert(1)</script>', 42, null, 'has space']);

test('a real railpack info output gives the expected stack', function (): void {
    $railpack = json_decode((string) file_get_contents(base_path('tests/Fixtures/railpack/info-node-no-lockfile.json')), true, 512, JSON_THROW_ON_ERROR);
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(succeededInspection(['package_manager' => null, 'railpack' => $railpack]));

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertJsonPath('data.stack', [
            'builder' => 'railpack',
            'provider' => 'node',
            'framework' => null,
            'package_manager' => 'npm',
        ]);
});

test('a lockfile detected by the agent wins over railpack', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(succeededInspection([
        'package_manager' => 'pnpm',
        'railpack' => ['metadata' => ['nodePackageManager' => 'npm']],
    ]));

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertJsonPath('data.stack.package_manager', 'pnpm');
});

test('there is no stack before an inspection succeeds', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['inspection_status' => ProjectInspectionStatus::Pending]);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertJsonPath('data.stack', null);
});

test('the framework comes only from fields railpack is known to have', function (array $metadata, ?string $expected): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(succeededInspection([
        'railpack' => ['detectedProviders' => ['node'], 'metadata' => $metadata],
    ]));

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertJsonPath('data.stack.framework', $expected);
})->with([
    'spa framework' => [['nodeSPAFramework' => 'vite'], 'vite'],
    'spa wins over runtime' => [['nodeSPAFramework' => 'astro', 'nodeRuntime' => 'next'], 'astro'],
    'framework runtime' => [['nodeRuntime' => 'next'], 'next'],
    'plain node runtime' => [['nodeRuntime' => 'node'], null],
    'plain bun runtime' => [['nodeRuntime' => 'bun'], null],
    'a key railpack does not have' => [['nodeFramework' => 'next', 'framework' => 'next'], null],
    'no metadata' => [[], null],
]);

test('a project with a root directory gets a builder for that directory', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create([
        ...succeededInspection(['root_directory' => 'apps/web', 'dockerfile_found' => true]),
        'root_directory' => 'apps/web',
    ]);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertJsonPath('data.stack.builder', 'dockerfile')
        ->assertJsonPath('data.stack.package_manager', 'npm');
});

test('the stack is unknown when the inspection looked at another directory', function (array $inspected, ?string $current): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create([...succeededInspection($inspected), 'root_directory' => $current]);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertJsonPath('data.stack', null)
        ->assertJsonPath('data.preview_status', 'succeeded');
})->with([
    'inspected before a root was set' => [[], 'apps/web'],
    'root changed' => [['root_directory' => 'apps/web'], 'apps/api'],
    'root cleared' => [['root_directory' => 'apps/web'], null],
]);

test('the inspection sends the root directory and the result remembers it', function (): void {
    fakeInspectionCommit();
    $node = inspectionNode();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['branch' => 'main', 'root_directory' => 'apps/web']);

    $this->actingAs($user, 'web')->postJson(route('api.v1.app.projects.inspection', $project))->assertAccepted();

    $command = AgentCommand::query()->where('project_id', $project->id)->sole();

    expect($command->payload['root_directory'])->toBe('apps/web');

    $this->withHeaders(inspectionHeaders($node))->getJson('/api/agent/v1/commands')
        ->assertJsonPath('data.0.payload.root_directory', 'apps/web');
    $this->withHeaders(inspectionHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();
    $this->withHeaders(inspectionHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => [
        'repository_url' => 'https://github.com/example/app',
        'commit_sha' => INSPECTION_SHA,
        'dockerfile_found' => false,
        'env_example_found' => false,
        'compose_found' => false,
        'manifests' => ['package.json'],
        'package_manager' => 'npm',
        'railpack' => ['detectedProviders' => ['node']],
    ]])->assertNoContent();

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertJsonPath('data.stack.builder', 'railpack')
        ->assertJsonPath('data.stack.provider', 'node');
});

test('the payload has no root directory when the project has none', function (): void {
    fakeInspectionCommit();
    inspectionNode();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['branch' => 'main']);

    $this->actingAs($user, 'web')->postJson(route('api.v1.app.projects.inspection', $project))->assertAccepted();

    expect(AgentCommand::query()->where('project_id', $project->id)->sole()->payload)->not->toHaveKey('root_directory');
});

test('changing the directory inspects the same commit again, and going back reuses the first', function (): void {
    fakeInspectionCommit();
    inspectionNode();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['branch' => 'main']);

    $this->actingAs($user, 'web');
    $this->postJson(route('api.v1.app.projects.inspection', $project))->assertAccepted();

    $project->update(['root_directory' => 'apps/web']);
    $this->postJson(route('api.v1.app.projects.inspection', $project))->assertAccepted();

    expect(AgentCommand::query()->where('project_id', $project->id)->count())->toBe(2);

    $this->postJson(route('api.v1.app.projects.inspection', $project))->assertAccepted();
    $project->update(['root_directory' => null]);
    $this->postJson(route('api.v1.app.projects.inspection', $project))->assertAccepted();

    expect(AgentCommand::query()->where('project_id', $project->id)->count())->toBe(2);
});

// ─── 90 day filter ───────────────────────────────────────────────────────────

test('the project list filters by 90 days', function (): void {
    $user = User::factory()->create();
    Project::factory()->for($user)->create(['name' => 'Recent', 'created_at' => now()->subDays(60)]);
    Project::factory()->for($user)->create(['name' => 'Old', 'created_at' => now()->subDays(120)]);

    $this->actingAs($user, 'web')
        ->getJson('/api/v1/app/projects?filter=90_days')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Recent');

    $this->getJson('/api/v1/app/projects?filter=91_days')->assertUnprocessable();
});

// ─── Delete ──────────────────────────────────────────────────────────────────

/** @return array{User, Project, Deployment} */
function servingProject(AgentNode $node): array
{
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['runtime_status' => RuntimeStatus::Running, 'status' => ProjectStatus::Active]);
    $deployment = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
    ]);
    $project->update(['active_deployment_id' => $deployment->id]);

    return [$user, $project->fresh(), $deployment];
}

test('deleting a serving project stops its workload through the agent', function (): void {
    $node = inspectionNode();
    [$user, $project, $deployment] = servingProject($node);

    $this->actingAs($user, 'web')
        ->deleteJson("/api/v1/app/projects/{$project->id}")
        ->assertNoContent();

    $this->assertSoftDeleted($project);

    $stop = AgentCommand::query()->where('project_id', $project->id)->where('type', AgentCommandType::StopProject)->sole();

    expect($stop->deployment_id)->toBe($deployment->id)
        ->and($stop->agent_node_id)->toBe($node->id)
        ->and($stop->status)->toBe(AgentCommandStatus::Pending)
        ->and($stop->expires_at)->toBeNull()
        ->and($stop->payload)->toBe(['remove_images' => true]);

    expect(AuditEvent::query()->where('action', 'project.deleted')->where('subject_id', $project->id)->exists())->toBeTrue();
});

test('the node can poll, claim, and complete the stop of a deleted project', function (): void {
    $node = inspectionNode();
    [$user, $project] = servingProject($node);

    $this->actingAs($user, 'web')->deleteJson("/api/v1/app/projects/{$project->id}")->assertNoContent();

    $stop = AgentCommand::query()->where('project_id', $project->id)->where('type', AgentCommandType::StopProject)->sole();

    $this->withHeaders(inspectionHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonPath('data.0.id', $stop->id)
        ->assertJsonPath('data.0.payload', ['remove_images' => true]);

    $this->withHeaders(inspectionHeaders($node))
        ->postJson("/api/agent/v1/commands/{$stop->id}/claim", [])
        ->assertOk()
        ->assertJsonPath('data.payload', ['remove_images' => true]);

    $this->withHeaders(inspectionHeaders($node))
        ->postJson("/api/agent/v1/commands/{$stop->id}/complete", ['result' => null])
        ->assertNoContent();

    $trashed = Project::withTrashed()->findOrFail($project->id);

    expect($trashed->runtime_status)->toBe(RuntimeStatus::Stopped)
        ->and($trashed->active_deployment_id)->toBeNull();
});

test('with the node offline the stop waits, and the node gets it when it returns', function (): void {
    $node = inspectionNode(overrides: ['status' => AgentNodeStatus::Offline]);
    [$user, $project] = servingProject($node);

    $this->actingAs($user, 'web')->deleteJson("/api/v1/app/projects/{$project->id}")->assertNoContent();

    $this->assertSoftDeleted($project);

    $this->withHeaders(inspectionHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertExactJson(['data' => []]);

    $this->withHeaders(inspectionHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', heartbeatPayload(['status' => 'ready']))
        ->assertOk();

    $this->withHeaders(inspectionHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertJsonPath('data.0.type', 'StopProject');
});

test('deleting a stopped or never deployed project creates no command', function (array $attributes): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create($attributes);

    $this->actingAs($user, 'web')->deleteJson("/api/v1/app/projects/{$project->id}")->assertNoContent();

    $this->assertSoftDeleted($project);
    expect(AgentCommand::query()->where('project_id', $project->id)->count())->toBe(0);
})->with([
    'never deployed' => [[]],
    'stopped' => [['runtime_status' => RuntimeStatus::Stopped]],
]);

test('deleting cancels queued work and asks a running attempt to stop', function (): void {
    $node = inspectionNode();
    [$user, $project] = servingProject($node);
    $queued = Deployment::factory()->for($project)->create(['status' => DeploymentStatus::Queued, 'sequence' => 2, 'agent_node_id' => $node->id]);
    $queuedCommand = AgentCommand::factory()->create([
        'project_id' => $project->id, 'deployment_id' => $queued->id, 'agent_node_id' => $node->id,
        'type' => AgentCommandType::DeployProject, 'status' => AgentCommandStatus::Pending,
    ]);
    $running = Deployment::factory()->for($project)->create(['status' => DeploymentStatus::Building, 'sequence' => 3, 'agent_node_id' => $node->id]);
    $runningCommand = AgentCommand::factory()->create([
        'project_id' => $project->id, 'deployment_id' => $running->id, 'agent_node_id' => $node->id,
        'type' => AgentCommandType::DeployProject, 'status' => AgentCommandStatus::Running,
    ]);
    $inspection = AgentCommand::factory()->create([
        'project_id' => $project->id, 'agent_node_id' => $node->id,
        'type' => AgentCommandType::InspectProject, 'status' => AgentCommandStatus::Pending,
    ]);

    $this->actingAs($user, 'web')->deleteJson("/api/v1/app/projects/{$project->id}")->assertNoContent();

    expect($queued->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($queuedCommand->fresh()->status)->toBe(AgentCommandStatus::Cancelled)
        ->and($inspection->fresh()->status)->toBe(AgentCommandStatus::Cancelled)
        ->and($running->fresh()->cancel_requested_at)->not->toBeNull();

    $this->withHeaders(inspectionHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertJsonPath('cancel_requested', [$runningCommand->id]);
});

test('an attempt that still succeeds after the delete gets stopped too', function (): void {
    $node = inspectionNode();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $running = Deployment::factory()->for($project)->create(['status' => DeploymentStatus::Routing, 'sequence' => 1, 'agent_node_id' => $node->id]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id, 'deployment_id' => $running->id, 'agent_node_id' => $node->id,
        'type' => AgentCommandType::DeployProject, 'status' => AgentCommandStatus::Running,
    ]);

    $this->actingAs($user, 'web')->deleteJson("/api/v1/app/projects/{$project->id}")->assertNoContent();

    $this->withHeaders(inspectionHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();

    $stop = AgentCommand::query()->where('project_id', $project->id)->where('type', AgentCommandType::StopProject)->sole();

    expect($stop->deployment_id)->toBe($running->id)
        ->and($stop->payload)->toBe(['remove_images' => true])
        ->and($running->fresh()->status)->toBe(DeploymentStatus::Succeeded);
});

test('a stop that is not a delete keeps the images and reaches the node as an empty payload', function (): void {
    $node = inspectionNode();
    [, $project] = servingProject($node);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", ['reason' => 'Emergency incident'])
        ->assertAccepted();

    $stop = AgentCommand::query()->where('project_id', $project->id)->where('type', AgentCommandType::StopProject)->sole();
    expect($stop->payload)->toBe([]);

    $poll = $this->withHeaders(inspectionHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonPath('data.0.id', $stop->id);

    // serde expects a map, so the empty payload must be `{}`, never `[]`.
    expect($poll->getContent())->toContain('"payload":{}')
        ->and($poll->getContent())->not->toContain('remove_images');
});

test('a retried delete of a deleted project is a 404 and adds no command', function (): void {
    $node = inspectionNode();
    [$user, $project] = servingProject($node);

    $this->actingAs($user, 'web');
    $this->deleteJson("/api/v1/app/projects/{$project->id}")->assertNoContent();
    $this->deleteJson("/api/v1/app/projects/{$project->id}")->assertNotFound();

    expect(AgentCommand::query()->where('project_id', $project->id)->count())->toBe(1);
});

test('a deleted slug is never reused, so an old route cannot serve a new project', function (): void {
    fakeInspectionCommit();
    $user = User::factory()->create();

    $this->actingAs($user, 'web');

    $first = $this->postJson('/api/v1/app/projects', [
        'name' => 'Same Name',
        'repository_url' => 'https://github.com/example/same',
        'branch' => 'main',
    ])->assertCreated()->json('data.id');

    $this->deleteJson("/api/v1/app/projects/{$first}")->assertNoContent();

    $preview = $this->getJson(route('api.v1.app.projects.domain-preview', ['name' => 'Same Name']))
        ->assertOk()
        ->json('data');

    expect($preview['slug'])->toBe('same-name-1');

    $second = $this->postJson('/api/v1/app/projects', [
        'name' => 'Same Name',
        'repository_url' => 'https://github.com/example/same',
        'branch' => 'main',
    ])->assertCreated()->json('data.id');

    expect(Project::query()->findOrFail($second)->slug)->toBe('same-name-1')
        ->and(Project::withTrashed()->findOrFail($first)->slug)->toBe('same-name');
});
