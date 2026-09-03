<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function commandAgent(
    string $token = 'agent-token',
    array $capabilities = ['docker-runtime', 'railpack-build'],
    AgentNodeStatus $status = AgentNodeStatus::Ready,
): AgentNode {
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => $status,
        'capabilities' => $capabilities,
        'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
    ]);
}

function commandHeaders(AgentNode $agent, string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'X-Agent-Id' => $agent->agent_id,
    ];
}

// ─── Poll Tests ──────────────────────────────────────────────────────────────

test('poll returns pending non-expired commands matching capabilities', function (): void {
    $agent = commandAgent('poll-token');

    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes(10),
        'payload' => ['repository_url' => 'https://github.com/example/app.git'],
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'poll-token'))
        ->getJson('/api/agent/v1/commands');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $command->id);
    $response->assertJsonPath('data.0.status', 'Pending');
});

test('poll excludes expired commands', function (): void {
    $agent = commandAgent('poll-token');

    AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
        'expires_at' => now()->subMinute(),
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'poll-token'))
        ->getJson('/api/agent/v1/commands');

    $response->assertOk();
    $response->assertJsonCount(0, 'data');
});

test('poll excludes commands not matching node capabilities', function (): void {
    $agent = commandAgent('poll-token', capabilities: ['caddy-file-routing']);

    AgentCommand::factory()->create([
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'poll-token'))
        ->getJson('/api/agent/v1/commands');

    $response->assertOk();
    $response->assertJsonCount(0, 'data');
});

test('poll excludes commands with future available_at', function (): void {
    $agent = commandAgent('poll-token');

    AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->addHour(),
        'expires_at' => now()->addDays(2),
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'poll-token'))
        ->getJson('/api/agent/v1/commands');

    $response->assertOk();
    $response->assertJsonCount(0, 'data');
});

test('poll respects explicit node ownership', function (): void {
    $otherAgent = commandAgent('other-token');
    $targetAgent = commandAgent('target-token');

    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::RestartProject,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes(10),
        'agent_node_id' => $targetAgent->id,
    ]);

    $otherResponse = $this->withHeaders(commandHeaders($otherAgent, 'other-token'))
        ->getJson('/api/agent/v1/commands');
    $otherResponse->assertOk();
    $otherResponse->assertJsonCount(0, 'data');

    $targetResponse = $this->withHeaders(commandHeaders($targetAgent, 'target-token'))
        ->getJson('/api/agent/v1/commands');
    $targetResponse->assertOk();
    $targetResponse->assertJsonCount(1, 'data');
    $targetResponse->assertJsonPath('data.0.id', $command->id);
});

test('poll returns empty array for draining node', function (): void {
    $agent = commandAgent('poll-token', status: AgentNodeStatus::Draining);

    AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'poll-token'))
        ->getJson('/api/agent/v1/commands');

    $response->assertOk();
    $response->assertJsonCount(0, 'data');
});

test('poll ordering is deterministic by available_at then created_at', function (): void {
    $agent = commandAgent('poll-token');

    $later = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes(10),
    ]);
    $earlier = AgentCommand::factory()->create([
        'type' => AgentCommandType::StopProject,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinutes(2),
        'expires_at' => now()->addMinutes(10),
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'poll-token'))
        ->getJson('/api/agent/v1/commands');

    $response->assertOk();
    $ids = $response->json('data.*.id');
    expect($ids[0])->toBe($earlier->id);
    expect($ids[1])->toBe($later->id);
});

test('poll respects batch size limit', function (): void {
    config(['sakala.agent.command_batch_size' => 2]);
    $agent = commandAgent('poll-token');

    for ($i = 0; $i < 5; $i++) {
        AgentCommand::factory()->create([
            'type' => AgentCommandType::HealthCheck,
            'status' => AgentCommandStatus::Pending,
            'available_at' => now()->subMinute(),
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    $response = $this->withHeaders(commandHeaders($agent, 'poll-token'))
        ->getJson('/api/agent/v1/commands');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

test('poll applies capability filtering before batch limit', function (): void {
    config(['sakala.agent.command_batch_size' => 2]);
    // Agent can run HealthCheck (docker-runtime) but NOT RefreshRoute (caddy-file-routing).
    $agent = commandAgent('poll-token', capabilities: ['docker-runtime']);

    // Two ineligible commands that sort BEFORE the eligible one.
    AgentCommand::factory()->create([
        'type' => AgentCommandType::RefreshRoute,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinutes(3),
        'expires_at' => now()->addMinutes(10),
    ]);
    AgentCommand::factory()->create([
        'type' => AgentCommandType::RefreshRoute,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinutes(2),
        'expires_at' => now()->addMinutes(10),
    ]);

    // The eligible command sits beyond the batch limit position.
    $eligible = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'poll-token'))
        ->getJson('/api/agent/v1/commands');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $eligible->id);
});

// ─── Claim Tests ─────────────────────────────────────────────────────────────

test('claim transitions Pending command to Claimed', function (): void {
    $agent = commandAgent('claim-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'claim-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/claim");

    $response->assertOk();
    $response->assertJsonPath('data.status', 'Claimed');
    $response->assertJsonPath('data.type', 'HealthCheck');

    expect($command->fresh()->status)->toBe(AgentCommandStatus::Claimed);
    expect($command->fresh()->attempts)->toBe(1);
    expect($command->fresh()->claimed_at)->not()->toBeNull();
});

test('claim sets agent_node_id on unowned command', function (): void {
    $agent = commandAgent('claim-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::RestartProject,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes(10),
        'agent_node_id' => null,
    ]);

    $this->withHeaders(commandHeaders($agent, 'claim-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/claim")
        ->assertOk();

    expect($command->fresh()->agent_node_id)->toBe($agent->id);
});

test('claim returns 409 for non-pending command', function (): void {
    $agent = commandAgent('claim-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Succeeded,
        'available_at' => now()->subMinute(),
        'completed_at' => now()->subMinute(),
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'claim-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/claim");

    $response->assertStatus(409);
    $response->assertJsonPath('status', 'Succeeded');
});

test('claim returns 409 for expired command', function (): void {
    $agent = commandAgent('claim-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
        'expires_at' => now()->subMinute(),
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'claim-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/claim");

    $response->assertStatus(409);
});

test('claim returns 409 for command owned by another node', function (): void {
    $otherAgent = commandAgent('other-token');
    $targetAgent = commandAgent('target-token');

    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::RestartProject,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes(10),
        'agent_node_id' => $targetAgent->id,
    ]);

    $response = $this->withHeaders(commandHeaders($otherAgent, 'other-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/claim");

    $response->assertStatus(409);
});

test('claim is atomic under contention', function (): void {
    $agentA = commandAgent('node-a-token');
    $agentB = commandAgent('node-b-token');

    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $aResult = $this->withHeaders(commandHeaders($agentA, 'node-a-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/claim");

    $bResult = $this->withHeaders(commandHeaders($agentB, 'node-b-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/claim");

    $aResult->assertOk();
    $bResult->assertStatus(409);

    expect($command->fresh()->status)->toBe(AgentCommandStatus::Claimed);
    expect($command->fresh()->agent_node_id)->toBe($agentA->id);
    expect($command->fresh()->attempts)->toBe(1);
});

test('claim returns 409 when node went draining between poll and claim', function (): void {
    $agent = commandAgent('claim-token', status: AgentNodeStatus::Ready);
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes(10),
    ]);

    // Node was poll-eligible as Ready, but changes state before the claim lands.
    $agent->forceFill(['status' => AgentNodeStatus::Draining])->save();

    $response = $this->withHeaders(commandHeaders($agent, 'claim-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/claim");

    $response->assertStatus(409);
    expect($command->fresh()->status)->toBe(AgentCommandStatus::Pending);
});

test('claim returns 409 when node is in maintenance state', function (): void {
    $agent = commandAgent('claim-token', status: AgentNodeStatus::Maintenance);
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'claim-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/claim");

    $response->assertStatus(409);
    expect($command->fresh()->status)->toBe(AgentCommandStatus::Pending);
});

test('claim returns 409 when node lost the capability for the command type', function (): void {
    $agent = commandAgent('claim-token', capabilities: ['docker-runtime']);
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes(10),
    ]);

    // Heartbeat arrives with a reduced capability set before the claim lands.
    $agent->forceFill(['capabilities' => ['caddy-file-routing']])->save();

    $response = $this->withHeaders(commandHeaders($agent, 'claim-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/claim");

    $response->assertStatus(409);
    expect($command->fresh()->status)->toBe(AgentCommandStatus::Pending);
});

// ─── Complete Tests ──────────────────────────────────────────────────────────

test('complete transitions Claimed to Succeeded', function (): void {
    $agent = commandAgent('complete-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Claimed,
        'claimed_at' => now(),
        'agent_node_id' => $agent->id,
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'complete-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", [
            'result' => ['success' => true],
        ]);

    $response->assertNoContent();
    expect($command->fresh()->status)->toBe(AgentCommandStatus::Succeeded);
    expect($command->fresh()->completed_at)->not()->toBeNull();
    expect($command->fresh()->result)->toBe(['success' => true]);
});

test('complete is idempotent when already Succeeded', function (): void {
    $agent = commandAgent('complete-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Succeeded,
        'completed_at' => now()->subMinute(),
        'agent_node_id' => $agent->id,
        'result' => ['prev' => true],
    ]);

    $first = $this->withHeaders(commandHeaders($agent, 'complete-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete");
    $second = $this->withHeaders(commandHeaders($agent, 'complete-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete");

    $first->assertNoContent();
    $second->assertNoContent();
    expect($command->fresh()->result)->toBe(['prev' => true]);
});

test('complete returns 409 from wrong agent', function (): void {
    $otherAgent = commandAgent('other-token');
    $ownerAgent = commandAgent('owner-token');

    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::RestartProject,
        'status' => AgentCommandStatus::Claimed,
        'claimed_at' => now(),
        'agent_node_id' => $ownerAgent->id,
    ]);

    $response = $this->withHeaders(commandHeaders($otherAgent, 'other-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete");

    $response->assertStatus(409);
});

test('complete by wrong agent on succeeded command returns 409, not idempotent 204', function (): void {
    $otherAgent = commandAgent('other-token');
    $ownerAgent = commandAgent('owner-token');

    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Succeeded,
        'completed_at' => now()->subMinute(),
        'agent_node_id' => $ownerAgent->id,
        'result' => ['owner_result' => true],
    ]);

    $response = $this->withHeaders(commandHeaders($otherAgent, 'other-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", [
            'result' => ['imposter' => true],
        ]);

    $response->assertStatus(409);
    $response->assertJsonPath('status', 'Succeeded');

    // Idempotent shortcut must not apply to a non-owner: result untouched.
    expect($command->fresh()->result)->toBe(['owner_result' => true]);
});

test('complete returns 409 when command is Failed', function (): void {
    $agent = commandAgent('complete-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Failed,
        'failed_at' => now(),
        'agent_node_id' => $agent->id,
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'complete-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete");

    $response->assertStatus(409);
});

test('complete returns 409 when command is Expired', function (): void {
    $agent = commandAgent('complete-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Expired,
        'agent_node_id' => $agent->id,
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'complete-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete");

    $response->assertStatus(409);
});

// ─── 409 Response Shape Regression Tests ─────────────────────────────────────
// docs/AGENT_API.md: 409 must carry the command's current safe state
// ({status, terminal_at when relevant}) — never a literal "conflict".

test('409 response from claim carries command state with terminal_at when terminal', function (): void {
    $agent = commandAgent('claim-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Succeeded,
        'available_at' => now()->subMinute(),
        'completed_at' => '2026-08-23T10:00:00Z',
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'claim-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/claim");

    $response->assertStatus(409);
    expect($response->json())->toBe([
        'status' => 'Succeeded',
        'terminal_at' => '2026-08-23T10:00:00+00:00',
    ]);
});

test('409 response from claim carries non-terminal state without terminal_at', function (): void {
    $agent = commandAgent('claim-token');
    $otherAgent = commandAgent('other-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Claimed,
        'claimed_at' => now()->subMinute(),
        'agent_node_id' => $otherAgent->id,
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'claim-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/claim");

    $response->assertStatus(409);
    expect($response->json())->toBe([
        'status' => 'Claimed',
        'terminal_at' => null,
    ]);
});

test('409 response from complete on failed command carries failed state', function (): void {
    $agent = commandAgent('complete-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Failed,
        'failed_at' => '2026-08-23T11:30:00Z',
        'agent_node_id' => $agent->id,
        'error_code' => 'build_failed',
        'error_message' => 'build crashed',
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'complete-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete");

    $response->assertStatus(409);
    expect($response->json())->toBe([
        'status' => 'Failed',
        'terminal_at' => '2026-08-23T11:30:00+00:00',
    ]);
    // Error details are state, not part of the conflict shape.
    $response->assertJsonMissingPath('error_code');
    $response->assertJsonMissingPath('error_message');
});

test('409 response from fail on succeeded command carries succeeded state', function (): void {
    $agent = commandAgent('fail-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Succeeded,
        'completed_at' => '2026-08-23T12:00:00Z',
        'agent_node_id' => $agent->id,
        'result' => ['requested_resources' => ['memory_mb' => 256]],
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'fail-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", [
            'error_code' => 'oops',
            'error_message' => 'should not happen',
        ]);

    $response->assertStatus(409);
    expect($response->json())->toBe([
        'status' => 'Succeeded',
        'terminal_at' => '2026-08-23T12:00:00+00:00',
    ]);
    // Result payload must never be reflected in the conflict body.
    $response->assertJsonMissingPath('result');
});

test('409 response from fail on expired command carries expired state', function (): void {
    $agent = commandAgent('fail-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Expired,
        'agent_node_id' => $agent->id,
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'fail-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", [
            'error_code' => 'too_late',
            'error_message' => 'already expired',
        ]);

    $response->assertStatus(409);
    expect($response->json())->toBe([
        'status' => 'Expired',
        'terminal_at' => null,
    ]);
});

// ─── Fail Tests ──────────────────────────────────────────────────────────────

test('fail transitions Claimed to Failed', function (): void {
    $agent = commandAgent('fail-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Claimed,
        'claimed_at' => now(),
        'agent_node_id' => $agent->id,
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'fail-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", [
            'error_code' => 'runtime_execution_failed',
            'error_message' => 'container crashed unexpectedly',
        ]);

    $response->assertNoContent();
    expect($command->fresh()->status)->toBe(AgentCommandStatus::Failed);
    expect($command->fresh()->failed_at)->not()->toBeNull();
    expect($command->fresh()->error_code)->toBe('runtime_execution_failed');
    expect($command->fresh()->error_message)->toBe('container crashed unexpectedly');
});

test('fail is idempotent when already Failed', function (): void {
    $agent = commandAgent('fail-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Failed,
        'failed_at' => now()->subMinute(),
        'agent_node_id' => $agent->id,
        'error_code' => 'old_error',
        'error_message' => 'old message',
    ]);

    $first = $this->withHeaders(commandHeaders($agent, 'fail-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", [
            'error_code' => 'new_error',
            'error_message' => 'new message',
        ]);
    $second = $this->withHeaders(commandHeaders($agent, 'fail-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", [
            'error_code' => 'newer_error',
            'error_message' => 'newer message',
        ]);

    $first->assertNoContent();
    $second->assertNoContent();
    expect($command->fresh()->error_code)->toBe('old_error');
});

test('fail returns 409 when command is Succeeded', function (): void {
    $agent = commandAgent('fail-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Succeeded,
        'completed_at' => now(),
        'agent_node_id' => $agent->id,
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'fail-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", [
            'error_code' => 'oops',
            'error_message' => 'something broke',
        ]);

    $response->assertStatus(409);
});

test('fail returns 409 from wrong agent', function (): void {
    $otherAgent = commandAgent('other-token');
    $ownerAgent = commandAgent('owner-token');

    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::RestartProject,
        'status' => AgentCommandStatus::Claimed,
        'claimed_at' => now(),
        'agent_node_id' => $ownerAgent->id,
    ]);

    $response = $this->withHeaders(commandHeaders($otherAgent, 'other-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", [
            'error_code' => 'boom',
            'error_message' => 'crashed',
        ]);

    $response->assertStatus(409);
});

test('fail by wrong agent on failed command returns 409, not idempotent 204', function (): void {
    $otherAgent = commandAgent('other-token');
    $ownerAgent = commandAgent('owner-token');

    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Failed,
        'failed_at' => now()->subMinute(),
        'agent_node_id' => $ownerAgent->id,
        'error_code' => 'owner_error',
        'error_message' => 'owner failure',
    ]);

    $response = $this->withHeaders(commandHeaders($otherAgent, 'other-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", [
            'error_code' => 'imposter_error',
            'error_message' => 'imposter failure',
        ]);

    $response->assertStatus(409);
    $response->assertJsonPath('status', 'Failed');

    // Idempotent shortcut must not apply to a non-owner: errors untouched.
    expect($command->fresh()->error_code)->toBe('owner_error');
    expect($command->fresh()->error_message)->toBe('owner failure');
});

test('fail sanitizes and size-limits error fields', function (): void {
    $agent = commandAgent('fail-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Claimed,
        'claimed_at' => now(),
        'agent_node_id' => $agent->id,
    ]);

    // Send values exactly at validation limits to exercise action-level truncation.
    $response = $this->withHeaders(commandHeaders($agent, 'fail-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", [
            'error_code' => str_repeat('a', 64),
            'error_message' => str_repeat('x', 1000),
        ]);

    $response->assertNoContent();
    $updated = $command->fresh();
    expect(strlen($updated->error_code))->toBe(64);
    expect(strlen($updated->error_message))->toBe(1000);
});

test('fail validates error_code and error_message are required', function (): void {
    $agent = commandAgent('fail-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Claimed,
        'claimed_at' => now(),
        'agent_node_id' => $agent->id,
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'fail-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", []);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['error_code', 'error_message']);
});

// ─── Resource Shape Tests ────────────────────────────────────────────────────

test('AgentCommandResource shapes DeployProject payload correctly', function (): void {
    $agent = commandAgent('poll-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes(10),
        'payload' => [
            'repository_url' => 'https://github.com/gmedia/example-app.git',
            'commit_sha' => '0123456789abcdef0123456789abcdef01234567',
            'domain' => 'portfolio.run.sakala.localhost',
            'container_port' => 3000,
            'builder' => 'auto',
            'environment' => ['APP_ENV' => 'production'],
            'resources' => ['memory_mb' => 256, 'cpu_millis' => 500, 'pids_limit' => 128],
            'timeouts' => ['build_timeout_seconds' => 600, 'start_timeout_seconds' => 120, 'command_timeout_seconds' => 900],
            'log_bounds' => ['max_line_length' => 4096, 'max_batch_lines' => 500, 'max_total_bytes' => 10485760],
        ],
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'poll-token'))
        ->getJson('/api/agent/v1/commands');

    $response->assertOk();
    $item = $response->json('data.0');
    expect($item['id'])->toBe($command->id);
    expect($item['type'])->toBe('DeployProject');
    expect($item['status'])->toBe('Pending');
    expect($item['project_id'])->toBeNull();
    expect($item['deployment_id'])->toBeNull();
    expect($item['payload']['repository_url'])
        ->toBe('https://github.com/gmedia/example-app.git');
    expect($item['payload']['commit_sha'])
        ->toBe('0123456789abcdef0123456789abcdef01234567');
});

test('AgentCommandResource shapes lifecycle command payload as empty object', function (): void {
    $agent = commandAgent('poll-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::SleepProject,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes(10),
        'payload' => [],
    ]);

    $response = $this->withHeaders(commandHeaders($agent, 'poll-token'))
        ->getJson('/api/agent/v1/commands');

    $response->assertOk();
    $item = $response->json('data.0');
    expect($item['type'])->toBe('SleepProject');
    expect($item['payload'])->toBe([]);
    expect($item['project_id'])->toBeNull();
    expect($item['deployment_id'])->toBeNull();
});
