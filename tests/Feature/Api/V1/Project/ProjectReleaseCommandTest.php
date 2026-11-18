<?php

declare(strict_types=1);

use App\Actions\Agent\AssignPendingCommandsAction;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\RuntimeStatus;
use App\Enums\WorkspaceRole;
use App\Exceptions\Runtime\ProjectReleaseCommandUnsupportedException;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Models\User;
use App\Support\Project\DeploymentConfigFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const RELEASE_COMMAND = 'php artisan migrate --force';

beforeEach(function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => '9c1f0e2d3b4a5968778695a4b3c2d1e0f9a8b7c6', 'commit' => ['message' => 'feat: release command']],
        ], 200),
    ]);
});

/** An online node that builds and runs projects, with the `release-command` capability unless told otherwise. */
function releaseNode(bool $releaseCommand = true, bool $replicas = false, array $overrides = []): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'desired_state' => AgentNodeDesiredState::Active,
        'protocol_version' => 4,
        'last_seen_at' => now(),
        'capabilities' => [
            'docker-runtime', 'dockerfile-build', 'railpack-build', 'caddy-file-routing',
            ...($releaseCommand ? [AgentNode::RELEASE_COMMAND_CAPABILITY] : []),
            ...($replicas ? [AgentNode::REPLICAS_CAPABILITY] : []),
        ],
        'metadata' => ['workloads' => []],
        ...$overrides,
    ]);
}

/** A team project under a profile that allows replicas, with its owner signed in. Nothing serves it yet. */
function releaseProject(array $attributes = []): Project
{
    $owner = User::factory()->create();
    $team = teamWorkspace($owner, attributes: ['limit_profile_id' => LimitProfile::factory()->create([
        'max_active_containers' => 10,
        'max_active_deployments' => 5,
        'max_replicas' => 4,
    ])->id]);

    test()->actingAs($owner, 'web');

    return Project::factory()->for($owner)->create(['workspace_id' => $team->id, 'branch' => 'main', ...$attributes]);
}

/** A succeeded deployment on `$node` that serves the project, so placement keeps the project there. */
function releaseServeOn(Project $project, AgentNode $node): Deployment
{
    $deployment = Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
        'agent_node_id' => $node->id,
    ]);
    $project->update(['active_deployment_id' => $deployment->id, 'runtime_status' => RuntimeStatus::Running]);

    return $deployment;
}

function releaseDeploy(Project $project): TestResponse
{
    return test()->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main']);
}

function releaseDeployCommand(string $deploymentId): AgentCommand
{
    return AgentCommand::query()->where('deployment_id', $deploymentId)->where('type', AgentCommandType::DeployProject)->sole();
}

// ─── Settings ────────────────────────────────────────────────────────────────

test('release_command is accepted on store and shown next to start_command', function (): void {
    $user = User::factory()->create();

    $id = $this->actingAs($user, 'web')
        ->postJson('/api/v1/app/projects', [
            'name' => 'Laravel App',
            'repository_url' => 'https://github.com/example/laravel-app',
            'branch' => 'main',
            'start_command' => 'php artisan serve',
            'release_command' => RELEASE_COMMAND,
        ])
        ->assertCreated()
        ->assertJsonPath('data.release_command', RELEASE_COMMAND)
        ->json('data.id');

    expect(Project::query()->findOrFail($id)->release_command)->toBe(RELEASE_COMMAND);

    $this->getJson("/api/v1/app/projects/{$id}")
        ->assertOk()
        ->assertJsonPath('data.start_command', 'php artisan serve')
        ->assertJsonPath('data.release_command', RELEASE_COMMAND);
});

test('a project without a release command shows null', function (): void {
    $project = releaseProject();

    $this->getJson("/api/v1/app/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.release_command', null);
});

test('release_command is set, kept on partial updates, and cleared with null or an empty string', function (mixed $clear): void {
    $project = releaseProject(['start_command' => 'node server.js']);

    $this->patchJson("/api/v1/app/projects/{$project->id}", ['release_command' => RELEASE_COMMAND])
        ->assertOk()
        ->assertJsonPath('data.release_command', RELEASE_COMMAND)
        ->assertJsonPath('data.start_command', 'node server.js');

    $this->patchJson("/api/v1/app/projects/{$project->id}", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.release_command', RELEASE_COMMAND);

    $this->patchJson("/api/v1/app/projects/{$project->id}", ['release_command' => $clear])
        ->assertOk()
        ->assertJsonPath('data.release_command', null);

    expect($project->fresh()->release_command)->toBeNull();
})->with(['null' => [null], 'empty string' => ['']]);

test('an invalid release command is rejected', function (mixed $value): void {
    $project = releaseProject();

    $response = $this->patchJson("/api/v1/app/projects/{$project->id}", ['release_command' => $value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['release_command']);

    if (is_string($value) && mb_strlen($value) <= 1000) {
        expect($response->json('errors.release_command.0'))->toContain('Perintah rilis harus satu baris');
    }

    expect($project->fresh()->release_command)->toBeNull();
})->with([
    'multi-line' => ["php artisan migrate\nrm -rf /"],
    'carriage return' => ["php artisan migrate\rnode b"],
    'null byte' => ["php\0artisan"],
    'tab' => ["php\tartisan migrate"],
    'delete character' => ["php artisan migrate\x7F"],
    'oversized' => [str_repeat('a', 1001)],
    'not a string' => [['php', 'artisan']],
]);

test('a release command of exactly 1000 characters is accepted', function (): void {
    $project = releaseProject();
    $command = str_repeat('a', 1000);

    $this->patchJson("/api/v1/app/projects/{$project->id}", ['release_command' => $command])
        ->assertOk()
        ->assertJsonPath('data.release_command', $command);
});

test('an invalid release command is rejected on store too', function (): void {
    $this->actingAs(User::factory()->create(), 'web')
        ->postJson('/api/v1/app/projects', [
            'name' => 'Laravel App',
            'repository_url' => 'https://github.com/example/laravel-app',
            'branch' => 'main',
            'release_command' => "php artisan migrate\nphp artisan db:seed",
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['release_command']);
});

test('viewers and outsiders cannot change the release command', function (): void {
    $project = releaseProject();
    $viewer = User::factory()->create();
    addWorkspaceMember($project->workspace, $viewer, WorkspaceRole::Viewer);

    foreach ([$viewer, User::factory()->create()] as $user) {
        app('auth')->forgetGuards();

        $this->actingAs($user, 'web')
            ->patchJson("/api/v1/app/projects/{$project->id}", ['release_command' => RELEASE_COMMAND])
            ->assertForbidden();
    }

    expect($project->fresh()->release_command)->toBeNull();
});

// ─── redeploy_required ───────────────────────────────────────────────────────

test('changing the release command asks for a redeploy, and changing it back clears it', function (): void {
    $node = releaseNode();
    $project = releaseProject();
    $id = releaseDeploy($project)->assertCreated()->json('data.id');
    Deployment::query()->whereKey($id)->update(['status' => DeploymentStatus::Succeeded]);
    $project->update(['active_deployment_id' => $id, 'runtime_status' => RuntimeStatus::Running]);

    expect(releaseDeployCommand($id)->agent_node_id)->toBe($node->id);

    $this->patchJson("/api/v1/app/projects/{$project->id}", ['release_command' => RELEASE_COMMAND])
        ->assertOk()
        ->assertJsonPath('data.redeploy_required', true);

    expect($project->fresh()->config_changed_at)->not->toBeNull();

    $this->patchJson("/api/v1/app/projects/{$project->id}", ['release_command' => null])
        ->assertOk()
        ->assertJsonPath('data.redeploy_required', false);
});

test('a project without a release command keeps the fingerprint it had before the field existed', function (): void {
    $project = releaseProject(['build_command' => 'npm run build', 'configured_port' => 8080]);
    $project->refreshEnvironmentFingerprint();

    // The canonical list DeploymentConfigFingerprint signed before release_command.
    $before = hash_hmac('sha256', json_encode([
        $project->branch,
        $project->effectivePort(),
        $project->build_command,
        $project->start_command,
        $project->root_directory,
        $project->replicas,
        $project->environment_fingerprint,
    ], JSON_THROW_ON_ERROR), (string) config('app.key'));

    expect(DeploymentConfigFingerprint::forProject($project))->toBe($before);

    $project->release_command = RELEASE_COMMAND;

    expect(DeploymentConfigFingerprint::forProject($project))->not->toBe($before);
});

// ─── Deploy payload ──────────────────────────────────────────────────────────

test('the deploy payload carries the release command only when it is set', function (): void {
    releaseNode();
    $withCommand = releaseProject(['release_command' => RELEASE_COMMAND]);
    $withCommandId = releaseDeploy($withCommand)->assertCreated()->json('data.id');

    $plain = releaseProject();
    $plainId = releaseDeploy($plain)->assertCreated()->json('data.id');

    $command = releaseDeployCommand($withCommandId);

    expect($command->payload['release_command'])->toBe(RELEASE_COMMAND)
        ->and($command->requestsReleaseCommand())->toBeTrue()
        ->and(releaseDeployCommand($plainId)->payload)->not->toHaveKey('release_command')
        ->and(releaseDeployCommand($plainId)->requestsReleaseCommand())->toBeFalse();
});

test('a wake never carries the release command', function (): void {
    $node = idleSleepNode(['capabilities' => ['docker-runtime', 'caddy-file-routing', 'idle-sleep', AgentNode::RELEASE_COMMAND_CAPABILITY]]);
    [$project] = sleepingIdleProject($node, ['release_command' => RELEASE_COMMAND]);

    $this->postJson("/api/v1/public/projects/{$project->slug}/wake")->assertStatus(202);

    expect(AgentCommand::query()->where('type', AgentCommandType::WakeProject)->sole()->payload)->toBe([]);
});

// ─── Placement ───────────────────────────────────────────────────────────────

test('a project with a release command only goes to a node that runs it', function (): void {
    releaseNode(releaseCommand: false, overrides: ['metadata' => ['resources' => ['memory_available_bytes' => 8 * 1024 ** 3]]]);
    $capable = releaseNode();
    $project = releaseProject(['release_command' => RELEASE_COMMAND]);

    $id = releaseDeploy($project)->assertCreated()->json('data.id');

    expect(releaseDeployCommand($id)->agent_node_id)->toBe($capable->id)
        ->and(Deployment::query()->findOrFail($id)->agent_node_id)->toBe($capable->id);
});

test('a project without a release command still goes to an older node', function (): void {
    $older = releaseNode(releaseCommand: false);
    $project = releaseProject();

    $id = releaseDeploy($project)->assertCreated()->json('data.id');

    expect(releaseDeployCommand($id)->agent_node_id)->toBe($older->id);
});

test('a deployment no node could run with its release command is refused up front', function (array $nodes): void {
    foreach ($nodes as $node) {
        $node();
    }
    $project = releaseProject(['release_command' => RELEASE_COMMAND]);

    $response = releaseDeploy($project)
        ->assertConflict()
        ->assertJsonPath('code', ProjectReleaseCommandUnsupportedException::CODE)
        ->assertJsonPath('code', 'PROJECT_RELEASE_COMMAND_UNSUPPORTED');

    expect($response->json('message'))->toContain('Kosongkan perintah rilis')
        ->and($response->json('message'))->toContain('operator memperbarui agent')
        ->and(Deployment::query()->where('project_id', $project->id)->exists())->toBeFalse()
        ->and(AgentCommand::query()->where('type', AgentCommandType::DeployProject)->exists())->toBeFalse();
})->with([
    'no node at all' => [[]],
    'only older nodes' => [[fn () => releaseNode(releaseCommand: false), fn () => releaseNode(releaseCommand: false)]],
    'a capable node that is not allowed work' => [[fn () => releaseNode(overrides: ['desired_state' => AgentNodeDesiredState::Drained])]],
]);

test('the node a project runs on must run the release command, even when another node could', function (): void {
    $older = releaseNode(releaseCommand: false);
    releaseNode();
    $project = releaseProject(['release_command' => RELEASE_COMMAND]);
    releaseServeOn($project, $older);

    releaseDeploy($project)
        ->assertConflict()
        ->assertJsonPath('code', 'PROJECT_RELEASE_COMMAND_UNSUPPORTED');

    // Once the operator upgrades the agent, the node advertises the capability.
    $older->update(['capabilities' => [...$older->capabilities, AgentNode::RELEASE_COMMAND_CAPABILITY]]);

    $id = releaseDeploy($project)->assertCreated()->json('data.id');

    expect(releaseDeployCommand($id)->agent_node_id)->toBe($older->id)
        ->and(releaseDeployCommand($id)->payload['release_command'])->toBe(RELEASE_COMMAND);
});

test('a capable node that is only offline is waited for, and older nodes never take the deployment', function (): void {
    $offline = releaseNode(overrides: ['status' => AgentNodeStatus::Offline, 'last_seen_at' => now()->subHour()]);
    $project = releaseProject(['release_command' => RELEASE_COMMAND]);

    $id = releaseDeploy($project)->assertCreated()->json('data.id');
    $command = releaseDeployCommand($id);

    expect($command->agent_node_id)->toBeNull();

    releaseNode(releaseCommand: false);

    expect(app(AssignPendingCommandsAction::class)->handle())->toBe(0)
        ->and($command->fresh()->agent_node_id)->toBeNull();

    $offline->update(['status' => AgentNodeStatus::Ready, 'last_seen_at' => now()]);

    expect(app(AssignPendingCommandsAction::class)->handle())->toBe(1)
        ->and($command->fresh()->agent_node_id)->toBe($offline->id)
        ->and(Deployment::query()->findOrFail($id)->agent_node_id)->toBe($offline->id);
});

test('a waiting deployment follows its payload, not a later change to the project', function (): void {
    $offline = releaseNode(overrides: ['status' => AgentNodeStatus::Offline, 'last_seen_at' => now()->subHour()]);
    $project = releaseProject(['release_command' => RELEASE_COMMAND]);
    $id = releaseDeploy($project)->assertCreated()->json('data.id');

    $project->update(['release_command' => null]);
    releaseNode(releaseCommand: false);

    expect(app(AssignPendingCommandsAction::class)->handle())->toBe(0)
        ->and(releaseDeployCommand($id)->agent_node_id)->toBeNull();

    $offline->update(['status' => AgentNodeStatus::Ready, 'last_seen_at' => now()]);

    expect(app(AssignPendingCommandsAction::class)->handle())->toBe(1)
        ->and(releaseDeployCommand($id)->agent_node_id)->toBe($offline->id);
});

test('replicas and a release command need one node that runs both', function (): void {
    releaseNode(releaseCommand: false, replicas: true);
    releaseNode(releaseCommand: true, replicas: false);
    $both = releaseNode(releaseCommand: true, replicas: true);
    $project = releaseProject(['release_command' => RELEASE_COMMAND, 'replicas' => 2]);

    $id = releaseDeploy($project)->assertCreated()->json('data.id');

    expect(releaseDeployCommand($id)->agent_node_id)->toBe($both->id)
        ->and(releaseDeployCommand($id)->payload)->toMatchArray(['replicas' => 2, 'release_command' => RELEASE_COMMAND]);
});

test('without a node for both, the missing replicas are reported before the release command', function (bool $replicaNodeExists, string $code): void {
    releaseNode(releaseCommand: true, replicas: false);

    if ($replicaNodeExists) {
        releaseNode(releaseCommand: false, replicas: true);
    }

    $project = releaseProject(['release_command' => RELEASE_COMMAND, 'replicas' => 2]);

    releaseDeploy($project)->assertConflict()->assertJsonPath('code', $code);
})->with([
    'no node runs replicas' => [false, 'PROJECT_REPLICAS_UNSUPPORTED'],
    'replica nodes cannot run the release command' => [true, 'PROJECT_RELEASE_COMMAND_UNSUPPORTED'],
]);
