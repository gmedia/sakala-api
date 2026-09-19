<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AgentNodeControlRequest;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\DeploymentLog;
use App\Models\Project;
use App\Models\ProjectControlRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function controlAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin]);
}

function controlNode(string $token = 'control-token', array $overrides = []): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'protocol_version' => 4,
        'desired_state' => AgentNodeDesiredState::Active,
        'capabilities' => ['docker-runtime', 'dockerfile-build', 'railpack-build'],
        'last_seen_at' => now(),
        'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
        ...$overrides,
    ]);
}

function controlHeaders(AgentNode $node, string $token = 'control-token'): array
{
    return ['Authorization' => 'Bearer '.$token, 'X-Agent-Id' => $node->agent_id];
}

/** @return array{project: Project, deployment: Deployment} */
function servedProject(AgentNode $node): array
{
    $project = Project::factory()->create(['status' => ProjectStatus::Active]);
    $deployment = Deployment::factory()->for($project)->create([
        'sequence' => 1, 'status' => DeploymentStatus::Succeeded, 'agent_node_id' => $node->id,
    ]);

    return compact('project', 'deployment');
}

// ─── Logs after completion ───────────────────────────────────────────────────

test('runtime logs keep flowing for a succeeded deploy command but not for other terminal commands', function (): void {
    $node = controlNode();
    ['project' => $project, 'deployment' => $deployment] = servedProject($node);
    $deploy = AgentCommand::factory()->create([
        'type' => AgentCommandType::DeployProject, 'status' => AgentCommandStatus::Succeeded,
        'project_id' => $project->id, 'deployment_id' => $deployment->id, 'agent_node_id' => $node->id,
        'payload' => ['log_bounds' => ['max_total_bytes' => 64]],
    ]);

    $line = fn (string $message): array => ['stream' => 'stdout', 'message' => $message, 'recorded_at' => now()->toIso8601String()];

    // docker logs --follow keeps reporting under the deploy command after complete.
    $this->withHeaders(controlHeaders($node))
        ->postJson("/api/agent/v1/commands/{$deploy->id}/logs", $line('GET / 200'))
        ->assertOk()
        ->assertJsonPath('data.first_sequence', 1);

    expect(DeploymentLog::query()->where('deployment_id', $deployment->id)->count())->toBe(1)
        ->and($deploy->fresh()->status)->toBe(AgentCommandStatus::Succeeded);

    // The cumulative budget still bounds the follower.
    $this->withHeaders(controlHeaders($node))
        ->postJson("/api/agent/v1/commands/{$deploy->id}/logs", $line(str_repeat('x', 60)))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['logs']);

    // Events after completion are still refused.
    $this->withHeaders(controlHeaders($node))
        ->postJson("/api/agent/v1/commands/{$deploy->id}/events", [
            'type' => 'deployment.runtime.ready', 'level' => 'info', 'message' => 'late', 'metadata' => [],
            'occurred_at' => now()->toIso8601String(),
        ])
        ->assertStatus(409)
        ->assertJsonPath('status', 'Succeeded');

    foreach ([AgentCommandStatus::Failed, AgentCommandStatus::Expired] as $status) {
        $closed = AgentCommand::factory()->create([
            'type' => AgentCommandType::DeployProject, 'status' => $status,
            'project_id' => $project->id, 'deployment_id' => $deployment->id, 'agent_node_id' => $node->id, 'payload' => [],
        ]);
        $this->withHeaders(controlHeaders($node))
            ->postJson("/api/agent/v1/commands/{$closed->id}/logs", $line('late'))
            ->assertStatus(409);
    }

    $health = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck, 'status' => AgentCommandStatus::Succeeded,
        'project_id' => $project->id, 'deployment_id' => $deployment->id, 'agent_node_id' => $node->id,
    ]);
    $this->withHeaders(controlHeaders($node))
        ->postJson("/api/agent/v1/commands/{$health->id}/logs", $line('late'))
        ->assertStatus(409);

    $other = controlNode('other-token');
    $this->withHeaders(controlHeaders($other, 'other-token'))
        ->postJson("/api/agent/v1/commands/{$deploy->id}/logs", $line('intruder'))
        ->assertStatus(409);
});

// ─── Cleanup ─────────────────────────────────────────────────────────────────

test('an admin can request runtime cleanup and the payload carries the approval gate', function (): void {
    $node = controlNode();

    $response = $this->actingAs(controlAdmin(), 'sanctum')
        ->postJson("/api/agent/v1/agents/{$node->id}/cleanup", [
            'reason' => 'disk pressure',
            'targets' => ['stale_images', 'stale_workspaces'],
        ])
        ->assertStatus(202)
        ->assertJsonPath('data.command.type', 'CleanupRuntime')
        ->assertJsonPath('data.command.status', 'Pending');

    $command = AgentCommand::query()->findOrFail($response->json('data.command.id'));
    expect($command->agent_node_id)->toBe($node->id)
        ->and($command->project_id)->toBeNull()
        ->and($command->deployment_id)->toBeNull()
        ->and($command->payload)->toBe(['approved' => true, 'targets' => ['stale_images', 'stale_workspaces']]);

    expect(AgentNodeControlRequest::query()->where('action', 'cleanup')->sole()->agent_command_id)->toBe($command->id)
        ->and(AuditEvent::query()->where('action', 'agent.node.cleanup_requested')->sole()->metadata['targets'])->toBe(['stale_images', 'stale_workspaces']);

    // Matches the v0.1.0 cleanup-runtime fixture shape on the wire.
    $fixture = agentCommandFixture('cleanup-runtime');
    $item = $this->withHeaders(controlHeaders($node))->getJson('/api/agent/v1/commands')->assertOk()->json('data.0');
    expect(array_keys($item))->toBe(array_keys($fixture))
        ->and(array_keys($item['payload']))->toBe(array_keys($fixture['payload']));

    $this->withHeaders(controlHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();
    $this->withHeaders(controlHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => [
            'approved' => true, 'cleaned_workspaces' => 2, 'cleaned_routes' => 0, 'reclaimed_image_bytes' => 4096,
        ]])
        ->assertNoContent();

    $audit = AuditEvent::query()->where('action', 'agent.command.completed')->where('subject_id', $command->id)->sole();
    expect($audit->metadata['reclaimed_image_bytes'])->toBe(4096)
        ->and($audit->metadata['cleaned_workspaces'])->toBe(2);
});

test('cleanup validation, authorization, and node state are enforced', function (): void {
    $node = controlNode();
    $admin = controlAdmin();

    $this->actingAs(User::factory()->create(['role' => UserRole::User]), 'sanctum')
        ->postJson("/api/agent/v1/agents/{$node->id}/cleanup", ['reason' => 'x', 'targets' => ['stale_images']])
        ->assertForbidden();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/agent/v1/agents/{$node->id}/cleanup", ['reason' => 'x', 'targets' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['targets']);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/agent/v1/agents/{$node->id}/cleanup", ['reason' => 'x', 'targets' => ['everything']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['targets.0']);

    // The approval gate is the control plane's alone.
    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/agent/v1/agents/{$node->id}/cleanup", ['reason' => 'x', 'targets' => ['stale_images'], 'approved' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['approved']);

    $draining = controlNode('draining-token', ['desired_state' => AgentNodeDesiredState::Draining]);
    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/agent/v1/agents/{$draining->id}/cleanup", ['reason' => 'x', 'targets' => ['stale_images']])
        ->assertStatus(409);

    $this->actingAs($admin, 'sanctum')->withHeaders(['Idempotency-Key' => 'cleanup-1'])
        ->postJson("/api/agent/v1/agents/{$node->id}/cleanup", ['reason' => 'x', 'targets' => ['stale_images']])
        ->assertStatus(202);
    $this->actingAs($admin, 'sanctum')->withHeaders(['Idempotency-Key' => 'cleanup-1'])
        ->postJson("/api/agent/v1/agents/{$node->id}/cleanup", ['reason' => 'x', 'targets' => ['stale_images']])
        ->assertStatus(202);
    $this->actingAs($admin, 'sanctum')->withHeaders(['Idempotency-Key' => 'cleanup-1'])
        ->postJson("/api/agent/v1/agents/{$node->id}/cleanup", ['reason' => 'x', 'targets' => ['stale_routes']])
        ->assertStatus(409);
    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/agent/v1/agents/{$node->id}/cleanup", ['reason' => 'again', 'targets' => ['stale_images']])
        ->assertStatus(409);

    expect(AgentCommand::query()->where('type', AgentCommandType::CleanupRuntime)->count())->toBe(1);
});

// ─── Reconcile ───────────────────────────────────────────────────────────────

test('an admin can request reconciliation and the payload is sent exactly as given', function (): void {
    $node = controlNode();
    ['project' => $project, 'deployment' => $deployment] = servedProject($node);

    $response = $this->actingAs(controlAdmin(), 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/reconcile", [
            'reason' => 'route missing after host restart',
            'desired_state' => 'running',
            'actions' => ['restore_route'],
        ])
        ->assertStatus(202)
        ->assertJsonPath('data.project_id', $project->id)
        ->assertJsonPath('data.command.type', 'ReconcileWorkload');

    $command = AgentCommand::query()->findOrFail($response->json('data.command.id'));
    expect($command->agent_node_id)->toBe($node->id)
        ->and($command->deployment_id)->toBe($deployment->id)
        ->and($command->payload)->toBe(['desired_state' => 'running', 'actions' => ['restore_route']]);

    expect(ProjectControlRequest::query()->where('action', 'reconcile')->sole()->agent_command_id)->toBe($command->id)
        ->and(AuditEvent::query()->where('action', 'project.reconcile_requested')->exists())->toBeTrue();

    $fixture = agentCommandFixture('reconcile-workload');
    $item = $this->withHeaders(controlHeaders($node))->getJson('/api/agent/v1/commands')->assertOk()->json('data.0');
    expect(array_keys($item))->toBe(array_keys($fixture))
        ->and(array_keys($item['payload']))->toBe(array_keys($fixture['payload']));

    $this->withHeaders(controlHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();
    $this->withHeaders(controlHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => [
            'desired_state' => 'running', 'actual_state' => 'running', 'in_sync' => true, 'drift_reason' => null,
            'container_id' => 'abc', 'actions_applied' => [['action' => 'restore_route']],
        ]])
        ->assertNoContent();

    $audit = AuditEvent::query()->where('action', 'project.reconcile_completed')->sole();
    expect($audit->metadata['in_sync'])->toBeTrue()
        ->and($audit->metadata['actions_applied'])->toBe([['action' => 'restore_route']])
        ->and($project->fresh()->runtime_status)->toBe($project->runtime_status);
});

test('a drift-only reconciliation carries no actions and mutations are never inferred', function (): void {
    $node = controlNode();
    ['project' => $project] = servedProject($node);

    $response = $this->actingAs(controlAdmin(), 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/reconcile", [
            'reason' => 'audit', 'desired_state' => 'running', 'actions' => [],
        ])
        ->assertStatus(202);

    expect(AgentCommand::query()->findOrFail($response->json('data.command.id'))->payload)
        ->toBe(['desired_state' => 'running', 'actions' => []]);
});

test('reconciliation is refused without a served workload, on suspended projects, and for non-admins', function (): void {
    $node = controlNode();
    $admin = controlAdmin();
    $body = ['reason' => 'x', 'desired_state' => 'running', 'actions' => []];

    $unserved = Project::factory()->create();
    $this->actingAs($admin, 'web')->postJson("/api/v1/admin/projects/{$unserved->id}/reconcile", $body)->assertStatus(409);

    ['project' => $suspended] = servedProject($node);
    $suspended->update(['status' => ProjectStatus::Suspended]);
    $this->actingAs($admin, 'web')->postJson("/api/v1/admin/projects/{$suspended->id}/reconcile", $body)->assertStatus(409);

    ['project' => $project] = servedProject($node);
    $this->actingAs($project->user, 'web')->postJson("/api/v1/admin/projects/{$project->id}/reconcile", $body)->assertForbidden();

    $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/reconcile", ['reason' => 'x', 'desired_state' => 'exploded', 'actions' => ['reboot']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['desired_state', 'actions.0']);

    $this->actingAs($admin, 'web')->withHeaders(['Idempotency-Key' => 'reconcile-1'])
        ->postJson("/api/v1/admin/projects/{$project->id}/reconcile", $body)->assertStatus(202);
    $this->actingAs($admin, 'web')->withHeaders(['Idempotency-Key' => 'reconcile-1'])
        ->postJson("/api/v1/admin/projects/{$project->id}/reconcile", $body)->assertStatus(202);
    $this->actingAs($admin, 'web')->withHeaders(['Idempotency-Key' => 'reconcile-1'])
        ->postJson("/api/v1/admin/projects/{$project->id}/reconcile", [...$body, 'desired_state' => 'stopped'])->assertStatus(409);
    // A new request while the first reconciliation is still in flight.
    $this->actingAs($admin, 'web')->withHeaders(['Idempotency-Key' => 'reconcile-2'])
        ->postJson("/api/v1/admin/projects/{$project->id}/reconcile", $body)->assertStatus(409);

    expect(AgentCommand::query()->where('type', AgentCommandType::ReconcileWorkload)->count())->toBe(1);
});
