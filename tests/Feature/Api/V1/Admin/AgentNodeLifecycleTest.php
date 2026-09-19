<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\UserRole;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AgentNodeControlRequest;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function lifecycleAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin]);
}

function lifecycleNode(string $token = 'lifecycle-token', array $overrides = []): AgentNode
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

function machineHeaders(AgentNode $node, string $token = 'lifecycle-token'): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'X-Agent-Id' => $node->agent_id,
    ];
}

// ─── Admin endpoint ──────────────────────────────────────────────────────────

test('an admin can drain a node and the desired state is stored with the command atomically', function (): void {
    $node = lifecycleNode();

    $response = $this->actingAs(lifecycleAdmin(), 'sanctum')
        ->postJson("/api/agent/v1/agents/{$node->id}/drain", ['reason' => 'kernel upgrade'])
        ->assertStatus(202)
        ->assertJsonPath('data.agent_node_id', $node->id)
        ->assertJsonPath('data.desired_state', 'draining')
        ->assertJsonPath('data.command.type', 'DrainNode')
        ->assertJsonPath('data.command.status', 'Pending');

    $command = AgentCommand::query()->findOrFail($response->json('data.command.id'));

    expect($node->fresh()->desired_state)->toBe(AgentNodeDesiredState::Draining)
        ->and($node->fresh()->status)->toBe(AgentNodeStatus::Ready)
        ->and($command->agent_node_id)->toBe($node->id)
        ->and($command->project_id)->toBeNull()
        ->and($command->deployment_id)->toBeNull()
        ->and($command->payload)->toBe([])
        ->and($command->request_context['reason'])->toBe('kernel upgrade');

    $request = AgentNodeControlRequest::query()->sole();
    expect($request->agent_command_id)->toBe($command->id)
        ->and($request->action->value)->toBe('drain');

    expect(AuditEvent::query()->where('action', 'agent.node.drain_requested')->where('subject_id', $node->id)->exists())->toBeTrue();
});

test('drain and resume are restricted to admins with a reason', function (): void {
    $node = lifecycleNode();

    $this->postJson("/api/agent/v1/agents/{$node->id}/drain", ['reason' => 'x'])->assertUnauthorized();

    $this->actingAs(User::factory()->create(['role' => UserRole::User]), 'sanctum')
        ->postJson("/api/agent/v1/agents/{$node->id}/drain", ['reason' => 'x'])
        ->assertForbidden();

    $this->actingAs(lifecycleAdmin(), 'sanctum')
        ->postJson("/api/agent/v1/agents/{$node->id}/resume", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);

    expect($node->fresh()->desired_state)->toBe(AgentNodeDesiredState::Active)
        ->and(AgentCommand::query()->count())->toBe(0);
});

test('the same idempotency key replays the drain request without a second command', function (): void {
    $node = lifecycleNode();
    $admin = lifecycleAdmin();
    $headers = ['Idempotency-Key' => 'drain-once'];

    $first = $this->actingAs($admin, 'sanctum')->withHeaders($headers)
        ->postJson("/api/agent/v1/agents/{$node->id}/drain", ['reason' => 'maintenance'])
        ->assertStatus(202);
    $second = $this->actingAs($admin, 'sanctum')->withHeaders($headers)
        ->postJson("/api/agent/v1/agents/{$node->id}/drain", ['reason' => 'maintenance'])
        ->assertStatus(202);

    expect($second->json('data.command.id'))->toBe($first->json('data.command.id'))
        ->and(AgentCommand::query()->count())->toBe(1)
        ->and(AgentNodeControlRequest::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'agent.node.drain_requested')->count())->toBe(1);

    // Same key, different intent: refused.
    $this->actingAs($admin, 'sanctum')->withHeaders($headers)
        ->postJson("/api/agent/v1/agents/{$node->id}/drain", ['reason' => 'something else'])
        ->assertStatus(409);

    $other = lifecycleNode('other-token');
    $this->actingAs($admin, 'sanctum')->withHeaders($headers)
        ->postJson("/api/agent/v1/agents/{$other->id}/drain", ['reason' => 'maintenance'])
        ->assertStatus(409);
});

test('draining twice or resuming an active node is refused', function (): void {
    $node = lifecycleNode();
    $admin = lifecycleAdmin();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/agent/v1/agents/{$node->id}/resume", ['reason' => 'already active'])
        ->assertStatus(409);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/agent/v1/agents/{$node->id}/drain", ['reason' => 'first'])
        ->assertStatus(202);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/agent/v1/agents/{$node->id}/drain", ['reason' => 'second'])
        ->assertStatus(409);

    expect(AgentCommand::query()->count())->toBe(1);
});

test('revoked nodes cannot be drained or resumed', function (): void {
    $node = lifecycleNode(overrides: ['auth_status' => AgentAuthStatus::Revoked]);

    $this->actingAs(lifecycleAdmin(), 'sanctum')
        ->postJson("/api/agent/v1/agents/{$node->id}/drain", ['reason' => 'x'])
        ->assertStatus(409);
});

test('the agent resource exposes lifecycle and protocol state to admins', function (): void {
    $node = lifecycleNode(overrides: ['desired_state' => AgentNodeDesiredState::Drained, 'protocol_version' => 4]);

    $this->actingAs(lifecycleAdmin(), 'sanctum')
        ->getJson("/api/agent/v1/agents/{$node->id}")
        ->assertOk()
        ->assertJsonPath('data.desired_state', 'drained')
        ->assertJsonPath('data.protocol_version', 4)
        ->assertJsonPath('data.last_seen_at', $node->last_seen_at?->toAtomString());
});

// ─── Node drain flow through the machine API ─────────────────────────────────

test('a drained node only receives its lifecycle command and resumes after the admin says so', function (): void {
    $node = lifecycleNode();
    $admin = lifecycleAdmin();
    $workload = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
        'agent_node_id' => $node->id,
    ]);

    $drainId = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/agent/v1/agents/{$node->id}/drain", ['reason' => 'rolling restart'])
        ->assertStatus(202)
        ->json('data.command.id');

    // The agent reads the new intent at bootstrap...
    $this->withHeaders(machineHeaders($node))
        ->getJson('/api/agent/v1/node-state')
        ->assertOk()
        ->assertJsonPath('data.desired_state', 'draining');

    // ...and polling only offers the drain command, never the workload.
    $this->withHeaders(machineHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $drainId)
        ->assertJsonPath('data.0.type', 'DrainNode');

    $this->withHeaders(machineHeaders($node))
        ->postJson("/api/agent/v1/commands/{$workload->id}/claim", [])
        ->assertStatus(409);

    $this->withHeaders(machineHeaders($node))->postJson("/api/agent/v1/commands/{$drainId}/claim", [])->assertOk();
    $this->withHeaders(machineHeaders($node))->postJson("/api/agent/v1/commands/{$drainId}/events", [
        'type' => 'node.drain.started', 'level' => 'info', 'message' => 'Node stopped accepting new workload commands.',
        'metadata' => [], 'occurred_at' => now()->toIso8601String(),
    ])->assertOk();
    $this->withHeaders(machineHeaders($node))
        ->postJson("/api/agent/v1/commands/{$drainId}/complete", ['result' => ['state' => 'draining']])
        ->assertNoContent();

    // The agent reports drained once its in-flight work is gone.
    $this->withHeaders(machineHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', heartbeatPayload(['status' => 'drained', 'metadata' => ['lifecycle_state' => 'drained']]))
        ->assertOk();

    expect($node->fresh()->status)->toBe(AgentNodeStatus::Drained)
        ->and($node->fresh()->desired_state)->toBe(AgentNodeDesiredState::Draining)
        ->and(AuditEvent::query()->where('action', 'agent.command.completed')->where('subject_id', $drainId)->exists())->toBeTrue();

    $this->withHeaders(machineHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertExactJson(['data' => []]);

    $resumeId = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/agent/v1/agents/{$node->id}/resume", ['reason' => 'restart done'])
        ->assertStatus(202)
        ->assertJsonPath('data.desired_state', 'active')
        ->json('data.command.id');

    // Still drained as far as the agent reports, but the resume command is visible.
    $this->withHeaders(machineHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $resumeId);

    $this->withHeaders(machineHeaders($node))->postJson("/api/agent/v1/commands/{$resumeId}/claim", [])->assertOk();
    $this->withHeaders(machineHeaders($node))
        ->postJson("/api/agent/v1/commands/{$resumeId}/complete", ['result' => [
            'state' => 'active',
            'capacity' => ['active_workloads' => 0, 'maximum_active_workloads' => 4, 'available_workload_slots' => 4],
        ]])
        ->assertNoContent();

    $this->withHeaders(machineHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', heartbeatPayload(['status' => 'ready']))
        ->assertOk();

    // Workload flows again.
    $this->withHeaders(machineHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonPath('data.0.id', $workload->id);
});

test('a failed resume leaves the node drained so bootstrap stays truthful', function (): void {
    $node = lifecycleNode(overrides: ['desired_state' => AgentNodeDesiredState::Drained, 'status' => AgentNodeStatus::Drained]);

    $resumeId = $this->actingAs(lifecycleAdmin(), 'sanctum')
        ->postJson("/api/agent/v1/agents/{$node->id}/resume", ['reason' => 'try again'])
        ->assertStatus(202)
        ->json('data.command.id');

    expect($node->fresh()->desired_state)->toBe(AgentNodeDesiredState::Active);

    $this->withHeaders(machineHeaders($node))->postJson("/api/agent/v1/commands/{$resumeId}/claim", [])->assertOk();
    $this->withHeaders(machineHeaders($node))->postJson("/api/agent/v1/commands/{$resumeId}/fail", [
        'error_code' => 'runtime_preflight_failed',
        'error_message' => 'node cannot resume because runtime preflight has fatal failures',
    ])->assertNoContent();

    expect($node->fresh()->desired_state)->toBe(AgentNodeDesiredState::Drained)
        ->and(AuditEvent::query()->where('action', 'agent.command.failed')->where('subject_id', $resumeId)->exists())->toBeTrue();

    $this->withHeaders(machineHeaders($node))
        ->getJson('/api/agent/v1/node-state')
        ->assertOk()
        ->assertJsonPath('data.desired_state', 'drained');
});
