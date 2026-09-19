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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
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

test('repository_access is derived from how the project was connected', function (): void {
    deployNode();
    ['user' => $user, 'project' => $public] = deployContext();
    $installation = GithubInstallation::factory()->create();

    // Installation-backed projects resolve the branch head with an App token;
    // seed the cached token so no GitHub App key is needed.
    Cache::put(
        'github-app-installation-token:'.$installation->id,
        Crypt::encryptString('ghs_test_installation_token'),
        now()->addHour(),
    );
    $private = Project::factory()->create([
        'user_id' => $user->id,
        'branch' => 'main',
        'github_installation_id' => $installation->id,
        'github_repository_id' => 4242,
    ]);

    $publicDeployment = createDeployment($user, $public);
    $privateDeployment = createDeployment($user, $private);

    $publicPayload = AgentCommand::query()->where('deployment_id', $publicDeployment->id)->sole()->payload;
    $privatePayload = AgentCommand::query()->where('deployment_id', $privateDeployment->id)->sole()->payload;

    expect($publicPayload['repository_access'])->toBe('public')
        ->and($privatePayload['repository_access'])->toBe('temporary_credential');
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
