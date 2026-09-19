<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Jobs\Deployment\SimulatedDeploymentJob;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\GithubInstallation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => '3e91b22a2e560a9f42b9d0921ca9b66c94462e5d', 'commit' => ['message' => 'feat: first']],
        ], 200),
    ]);
});

function deployNode(array $overrides = []): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'protocol_version' => 4,
        'desired_state' => AgentNodeDesiredState::Active,
        'capabilities' => ['docker-runtime', 'dockerfile-build', 'railpack-build'],
        'last_seen_at' => now(),
        ...$overrides,
    ]);
}

/** @return array{user: User, project: Project} */
function deployContext(array $projectOverrides = []): array
{
    $user = User::factory()->create();
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'branch' => 'main',
        ...$projectOverrides,
    ]);

    return compact('user', 'project');
}

function createDeployment(User $user, Project $project): Deployment
{
    $response = test()->actingAs($user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertCreated();

    return Deployment::query()->findOrFail($response->json('data.id'));
}

test('a new deployment and its command are pinned to the only eligible node', function (): void {
    $node = deployNode();
    deployNode(['protocol_version' => 3]);
    deployNode(['desired_state' => AgentNodeDesiredState::Draining]);
    deployNode(['status' => AgentNodeStatus::Offline]);
    deployNode(['last_seen_at' => now()->subMinutes(5)]);
    deployNode(['capabilities' => ['docker-runtime']]);
    ['user' => $user, 'project' => $project] = deployContext();

    $deployment = createDeployment($user, $project);
    $command = AgentCommand::query()->where('deployment_id', $deployment->id)->sole();

    expect($deployment->agent_node_id)->toBe($node->id)
        ->and($command->agent_node_id)->toBe($node->id)
        ->and($command->type)->toBe(AgentCommandType::DeployProject)
        ->and($command->status)->toBe(AgentCommandStatus::Pending);
});

test('without an eligible node the command waits unassigned and is offered to nobody', function (): void {
    $stale = deployNode(['last_seen_at' => now()->subMinutes(5), 'token_hash' => hash_hmac('sha256', 'stale-token', (string) config('app.key'))]);
    ['user' => $user, 'project' => $project] = deployContext();

    $deployment = createDeployment($user, $project);
    $command = AgentCommand::query()->where('deployment_id', $deployment->id)->sole();

    expect($deployment->agent_node_id)->toBeNull()
        ->and($command->agent_node_id)->toBeNull();

    $this->withHeaders(['Authorization' => 'Bearer stale-token', 'X-Agent-Id' => $stale->agent_id])
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

test('a heartbeat from a node that became eligible assigns waiting deployments to it', function (): void {
    $node = deployNode([
        'last_seen_at' => now()->subMinutes(5),
        'protocol_version' => null,
        'capabilities' => [],
        'token_hash' => hash_hmac('sha256', 'late-token', (string) config('app.key')),
    ]);
    ['user' => $user, 'project' => $project] = deployContext();
    $deployment = createDeployment($user, $project);

    expect($deployment->agent_node_id)->toBeNull();

    $this->withHeaders(['Authorization' => 'Bearer late-token', 'X-Agent-Id' => $node->agent_id])
        ->postJson('/api/agent/v1/heartbeat', heartbeatPayload([
            'capabilities' => ['docker-runtime', 'dockerfile-build', 'railpack-build'],
        ]))
        ->assertOk();

    $command = AgentCommand::query()->where('deployment_id', $deployment->id)->sole();

    expect($command->agent_node_id)->toBe($node->id)
        ->and($deployment->fresh()->agent_node_id)->toBe($node->id);

    $this->withHeaders(['Authorization' => 'Bearer late-token', 'X-Agent-Id' => $node->agent_id])
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonPath('data.0.id', $command->id);
});

test('the assignment sweep pins waiting deployments to an eligible node', function (): void {
    ['user' => $user, 'project' => $project] = deployContext();
    $deployment = createDeployment($user, $project);
    $node = deployNode();

    $this->artisan('agent:assign-commands')
        ->expectsOutputToContain('Assigned 1 agent command(s).')
        ->assertSuccessful();

    expect(AgentCommand::query()->where('deployment_id', $deployment->id)->sole()->agent_node_id)->toBe($node->id)
        ->and($deployment->fresh()->agent_node_id)->toBe($node->id);
});

test('redeploys stick to the node that already serves the project', function (): void {
    $busy = deployNode();
    $current = deployNode();
    AgentCommand::factory()->count(3)->create([
        'agent_node_id' => $current->id,
        'status' => AgentCommandStatus::Running,
    ]);
    ['user' => $user, 'project' => $project] = deployContext();
    Deployment::factory()->for($project)->create([
        'sequence' => 1,
        'status' => DeploymentStatus::Succeeded,
        'agent_node_id' => $current->id,
    ]);

    $deployment = createDeployment($user, $project);

    expect($deployment->agent_node_id)->toBe($current->id)
        ->and($deployment->agent_node_id)->not->toBe($busy->id);
});

test('a project already served by a node waits for that node instead of moving elsewhere', function (): void {
    $serving = deployNode([
        'last_seen_at' => now()->subMinutes(5),
        'token_hash' => hash_hmac('sha256', 'serving-token', (string) config('app.key')),
    ]);
    $spare = deployNode();
    ['user' => $user, 'project' => $project] = deployContext();
    Deployment::factory()->for($project)->create([
        'sequence' => 1,
        'status' => DeploymentStatus::Succeeded,
        'agent_node_id' => $serving->id,
    ]);

    $deployment = createDeployment($user, $project);
    $command = AgentCommand::query()->where('deployment_id', $deployment->id)->sole();

    // Never implicitly migrate: the old workload on the serving node could
    // not be cleaned up from another node.
    expect($deployment->agent_node_id)->toBeNull()
        ->and($command->agent_node_id)->toBeNull()
        ->and($spare->id)->not->toBe($serving->id);

    $this->artisan('agent:assign-commands')->expectsOutputToContain('Assigned 0 agent command(s).');
    expect($command->fresh()->agent_node_id)->toBeNull();

    // When the serving node comes back it picks the deployment up itself.
    $this->withHeaders(['Authorization' => 'Bearer serving-token', 'X-Agent-Id' => $serving->agent_id])
        ->postJson('/api/agent/v1/heartbeat', heartbeatPayload([
            'capabilities' => ['docker-runtime', 'dockerfile-build', 'railpack-build'],
        ]))
        ->assertOk();

    expect($command->fresh()->agent_node_id)->toBe($serving->id)
        ->and($deployment->fresh()->agent_node_id)->toBe($serving->id);
});

test('a waiting deploy command never expires on its own', function (): void {
    ['user' => $user, 'project' => $project] = deployContext();

    $deployment = createDeployment($user, $project);
    $command = AgentCommand::query()->where('deployment_id', $deployment->id)->sole();

    expect($command->expires_at)->toBeNull()
        ->and($command->available_at)->not->toBeNull();

    $this->travel(2)->hours();

    deployNode();
    $this->artisan('agent:assign-commands')->expectsOutputToContain('Assigned 1 agent command(s).');

    expect($command->fresh()->agent_node_id)->not->toBeNull();
});

test('assigned but unclaimed commands count as node load', function (): void {
    $loaded = deployNode();
    $idle = deployNode();
    AgentCommand::factory()->count(2)->create([
        'agent_node_id' => $loaded->id,
        'status' => AgentCommandStatus::Pending,
    ]);
    ['user' => $user, 'project' => $project] = deployContext();

    $deployment = createDeployment($user, $project);

    expect($deployment->agent_node_id)->toBe($idle->id);
});

test('public projects deploy with repository_access public', function (): void {
    deployNode();
    ['user' => $user, 'project' => $public] = deployContext();

    $deployment = createDeployment($user, $public);
    $payload = AgentCommand::query()->where('deployment_id', $deployment->id)->sole()->payload;

    expect($payload['repository_access'])->toBe('public');
});

test('installation-backed projects are refused until the agent can lease a repository credential', function (): void {
    deployNode();
    $user = User::factory()->create();
    $installation = GithubInstallation::factory()->create();
    $private = Project::factory()->create([
        'user_id' => $user->id,
        'branch' => 'main',
        'github_installation_id' => $installation->id,
        'github_repository_id' => 4242,
    ]);

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/app/projects/{$private->id}/deployments", ['branch' => 'main'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Deployments for repositories connected through a GitHub App installation are not available yet.');

    // Nothing is enqueued that a stock agent could not complete, and GitHub
    // is never contacted for the branch head.
    expect(Deployment::query()->where('project_id', $private->id)->exists())->toBeFalse()
        ->and(AgentCommand::query()->where('project_id', $private->id)->exists())->toBeFalse();
    Http::assertNothingSent();
});

test('the simulated lifecycle is not dispatched unless explicitly enabled', function (): void {
    Queue::fake();
    deployNode();
    ['user' => $user, 'project' => $project] = deployContext();

    createDeployment($user, $project);
    Queue::assertNotPushed(SimulatedDeploymentJob::class);

    config(['sakala.deployments.simulate' => true]);
    ['user' => $user, 'project' => $project] = deployContext();

    createDeployment($user, $project);
    Queue::assertPushed(SimulatedDeploymentJob::class, 1);
});
