<?php

declare(strict_types=1);

use App\Actions\Agent\ExpireAgentCommandsAction;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Enums\UserRole;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function cancelNode(string $token = 'cancel-token', array $overrides = []): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'capabilities' => ['docker-runtime', 'dockerfile-build', 'railpack-build'],
        'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
        ...$overrides,
    ]);
}

function cancelHeaders(AgentNode $node, string $token = 'cancel-token'): array
{
    return ['Authorization' => 'Bearer '.$token, 'X-Agent-Id' => $node->agent_id];
}

/** @return array{User, Project, Deployment, AgentCommand} */
function cancelAttempt(?AgentNode $node, DeploymentStatus $status, AgentCommandStatus $commandStatus, array $projectAttributes = []): array
{
    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create($projectAttributes);
    $deployment = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node?->id,
        'status' => $status,
        'sequence' => 1,
    ]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node?->id,
        'type' => AgentCommandType::DeployProject,
        'status' => $commandStatus,
    ]);

    return [$user, $project, $deployment, $command];
}

function cancelUrl(Project $project, Deployment $deployment): string
{
    return "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/cancel";
}

test('a pending command cancels immediately and frees the deployment slot', function (): void {
    [$user, $project, $deployment, $command] = cancelAttempt(null, DeploymentStatus::Queued, AgentCommandStatus::Pending);

    $this->actingAs($user, 'web')
        ->postJson(cancelUrl($project, $deployment))
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.queue_state', null)
        ->assertJsonPath('data.failure', null);

    expect($command->fresh()->status)->toBe(AgentCommandStatus::Cancelled)
        ->and($deployment->fresh()->cancelled_at)->not->toBeNull()
        ->and(Deployment::query()->where('project_id', $project->id)->active()->count())->toBe(0);
});

test('a node cannot claim a command cancelled before it got there', function (): void {
    $node = cancelNode();
    [$user, $project, $deployment, $command] = cancelAttempt($node, DeploymentStatus::Queued, AgentCommandStatus::Pending);

    $this->actingAs($user, 'web')->postJson(cancelUrl($project, $deployment))->assertOk();

    $this->withHeaders(cancelHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/claim", [])
        ->assertConflict()
        ->assertJsonPath('status', 'Cancelled');
});

test('a running command records the intent, and the agent learns it from the next poll', function (AgentCommandStatus $status): void {
    $node = cancelNode();
    [$user, $project, $deployment, $command] = cancelAttempt($node, DeploymentStatus::Building, $status);

    $this->actingAs($user, 'web')
        ->postJson(cancelUrl($project, $deployment))
        ->assertAccepted()
        ->assertJsonPath('data.status', 'building')
        ->assertJsonPath('data.cancel_requested_at', fn (mixed $value): bool => is_string($value));

    expect($command->fresh()->status)->toBe($status);

    $this->withHeaders(cancelHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonPath('cancel_requested', [$command->id]);
})->with([AgentCommandStatus::Claimed, AgentCommandStatus::Running]);

test('the poll body is unchanged while nothing is being cancelled', function (): void {
    $node = cancelNode();
    cancelAttempt($node, DeploymentStatus::Building, AgentCommandStatus::Running);

    $this->withHeaders(cancelHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

test('a node only sees cancellations for its own commands', function (): void {
    $node = cancelNode();
    $other = cancelNode('other-token');
    [$user, $project, $deployment] = cancelAttempt($other, DeploymentStatus::Building, AgentCommandStatus::Running);

    $this->actingAs($user, 'web')->postJson(cancelUrl($project, $deployment))->assertAccepted();

    $this->withHeaders(cancelHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertExactJson(['data' => []]);
});

test('a draining node still hears about cancellations', function (): void {
    $node = cancelNode(overrides: ['desired_state' => AgentNodeDesiredState::Draining, 'status' => AgentNodeStatus::Draining]);
    [$user, $project, $deployment, $command] = cancelAttempt($node, DeploymentStatus::Building, AgentCommandStatus::Running);

    $this->actingAs($user, 'web')->postJson(cancelUrl($project, $deployment))->assertAccepted();

    $this->withHeaders(cancelHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonPath('cancel_requested', [$command->id]);
});

test('repeating a cancel keeps the first request and adds nothing', function (): void {
    $node = cancelNode();
    [$user, $project, $deployment] = cancelAttempt($node, DeploymentStatus::Building, AgentCommandStatus::Running);

    $this->actingAs($user, 'web');

    $first = $this->postJson(cancelUrl($project, $deployment))->assertAccepted()->json('data.cancel_requested_at');
    $this->travel(5)->seconds();
    $second = $this->postJson(cancelUrl($project, $deployment))->assertAccepted()->json('data.cancel_requested_at');

    expect($second)->toBe($first);
});

test('runtime_cancelled ends the command and deployment as cancelled', function (): void {
    $node = cancelNode();
    [$user, $project, $deployment, $command] = cancelAttempt($node, DeploymentStatus::Building, AgentCommandStatus::Running, [
        'runtime_status' => RuntimeStatus::Deploying,
    ]);

    $this->actingAs($user, 'web')->postJson(cancelUrl($project, $deployment))->assertAccepted();

    $this->withHeaders(cancelHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", [
            'error_code' => 'runtime_cancelled',
            'error_message' => 'runtime operation was cancelled',
        ])
        ->assertNoContent();

    expect($command->fresh()->status)->toBe(AgentCommandStatus::Cancelled)
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($deployment->fresh()->failure_code)->toBeNull()
        ->and($project->fresh()->runtime_status)->toBe(RuntimeStatus::NotDeployed);

    // A retry of the report is idempotent, and the id leaves the poll.
    $this->withHeaders(cancelHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", [
            'error_code' => 'runtime_cancelled',
            'error_message' => 'runtime operation was cancelled',
        ])
        ->assertNoContent();

    $this->withHeaders(cancelHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertExactJson(['data' => []]);

    $this->actingAs($user, 'web')
        ->postJson(cancelUrl($project, $deployment))
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

test('runtime_cancelled nobody asked for ends cancelled and keeps the reason', function (): void {
    $node = cancelNode();
    [, , $deployment, $command] = cancelAttempt($node, DeploymentStatus::Building, AgentCommandStatus::Running);

    $this->withHeaders(cancelHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", [
            'error_code' => 'runtime_cancelled',
            'error_message' => 'node is shutting down',
        ])
        ->assertNoContent();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($deployment->fresh()->failure_code)->toBe('runtime_cancelled')
        ->and($deployment->fresh()->failure_detail)->toBe('node is shutting down')
        ->and($deployment->fresh()->failure_stage)->toBe(DeploymentStatus::Building);
});

test('a cancelled redeploy leaves the previous deployment serving', function (): void {
    $node = cancelNode();
    [$user, $project, $deployment, $command] = cancelAttempt($node, DeploymentStatus::Building, AgentCommandStatus::Running, [
        'runtime_status' => RuntimeStatus::Deploying,
    ]);
    $live = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 0,
    ]);
    $project->update(['active_deployment_id' => $live->id]);

    $this->actingAs($user, 'web')->postJson(cancelUrl($project, $deployment))->assertAccepted();
    $this->withHeaders(cancelHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", ['error_code' => 'runtime_cancelled', 'error_message' => 'x'])
        ->assertNoContent();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($project->fresh()->active_deployment_id)->toBe($live->id);
});

test('an agent past cutover may still complete, and the deployment succeeds', function (): void {
    $node = cancelNode();
    [$user, $project, $deployment, $command] = cancelAttempt($node, DeploymentStatus::Routing, AgentCommandStatus::Running);

    $this->actingAs($user, 'web')->postJson(cancelUrl($project, $deployment))->assertAccepted();

    $this->withHeaders(cancelHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($project->fresh()->active_deployment_id)->toBe($deployment->id);
});

test('a real failure after a cancel request stays a failure', function (): void {
    $node = cancelNode();
    [$user, $project, $deployment, $command] = cancelAttempt($node, DeploymentStatus::Building, AgentCommandStatus::Running);

    $this->actingAs($user, 'web')->postJson(cancelUrl($project, $deployment))->assertAccepted();

    $this->withHeaders(cancelHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", ['error_code' => 'runtime_build_failed', 'error_message' => 'x'])
        ->assertNoContent();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($command->fresh()->status)->toBe(AgentCommandStatus::Failed);
});

test('a lease that expires after a cancel request ends cancelled', function (): void {
    $node = cancelNode();
    [$user, $project, $deployment, $command] = cancelAttempt($node, DeploymentStatus::Building, AgentCommandStatus::Running);

    $this->actingAs($user, 'web')->postJson(cancelUrl($project, $deployment))->assertAccepted();
    $command->update(['lease_expires_at' => now()->subMinute()]);

    app(ExpireAgentCommandsAction::class)->handle();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($command->fresh()->status)->toBe(AgentCommandStatus::Expired);
});

test('a finished deployment answers clearly', function (DeploymentStatus $status): void {
    [$user, $project, $deployment] = cancelAttempt(null, $status, AgentCommandStatus::Succeeded);

    $this->actingAs($user, 'web')
        ->postJson(cancelUrl($project, $deployment))
        ->assertConflict()
        ->assertJsonPath('code', 'DEPLOYMENT_ALREADY_FINISHED')
        ->assertJsonPath('status', $status->value);
})->with([DeploymentStatus::Succeeded, DeploymentStatus::Failed]);

test('only the owner or an admin can cancel', function (): void {
    [, $project, $deployment, $command] = cancelAttempt(null, DeploymentStatus::Queued, AgentCommandStatus::Pending);

    $this->postJson(cancelUrl($project, $deployment))->assertUnauthorized();

    $this->actingAs(User::factory()->create(['role' => UserRole::User]), 'web')
        ->postJson(cancelUrl($project, $deployment))
        ->assertForbidden();

    expect($command->fresh()->status)->toBe(AgentCommandStatus::Pending);

    app('auth')->forgetGuards();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]), 'web')
        ->postJson(cancelUrl($project, $deployment))
        ->assertOk();
});

test('a deployment cannot be cancelled through another project', function (): void {
    [$user, , $deployment] = cancelAttempt(null, DeploymentStatus::Queued, AgentCommandStatus::Pending);
    $otherProject = Project::factory()->for($user)->create();

    $this->actingAs($user, 'web')
        ->postJson(cancelUrl($otherProject, $deployment))
        ->assertNotFound();
});

test('the owner of a suspended project can still cancel', function (): void {
    [$user, $project, $deployment] = cancelAttempt(null, DeploymentStatus::Queued, AgentCommandStatus::Pending, [
        'status' => ProjectStatus::Suspended,
    ]);

    $this->actingAs($user, 'web')->postJson(cancelUrl($project, $deployment))->assertOk();
});

test('queue_state says whether a queued deployment is waiting for a node', function (?array $nodeOverrides, ?string $expected): void {
    $node = $nodeOverrides === null ? null : cancelNode(overrides: $nodeOverrides);
    [$user, $project, $deployment] = cancelAttempt($node, DeploymentStatus::Queued, AgentCommandStatus::Pending);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}")
        ->assertOk()
        ->assertJsonPath('data.queue_state', $expected);

    $this->getJson("/api/v1/app/projects/{$project->id}/deployments")
        ->assertOk()
        ->assertJsonPath('data.0.queue_state', $expected);
})->with([
    'no node' => [null, 'waiting_for_node'],
    'assigned to a ready node' => [[], 'assigned'],
    'assigned to an offline node' => [['status' => AgentNodeStatus::Offline], 'waiting_for_node'],
    'assigned to a draining node' => [['desired_state' => AgentNodeDesiredState::Draining], 'waiting_for_node'],
]);

test('queue_state is null once the deployment has left the queue', function (): void {
    $node = cancelNode();
    [$user, $project, $deployment] = cancelAttempt($node, DeploymentStatus::Building, AgentCommandStatus::Running);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}")
        ->assertJsonPath('data.queue_state', null);
});
