<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\DeploymentEvent;
use App\Models\DeploymentLog;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Replays the batch report bodies sakala-agent v0.2.0 actually sends. Since
 * v0.2.0 the agent never sends single-object bodies, always carries an
 * Idempotency-Key (UUID v4 per request, reused on retry), and only treats a
 * 200 as delivered when the acknowledgement is complete and
 * accepted_count == count(items).
 */
function batchNode(string $token = 'batch-token'): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'protocol_version' => 4,
        'desired_state' => AgentNodeDesiredState::Active,
        'capabilities' => ['docker-runtime', 'dockerfile-build', 'railpack-build'],
        'last_seen_at' => now(),
        'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
    ]);
}

/** @return array{node: AgentNode, command: AgentCommand, deployment: Deployment} */
function batchContext(AgentCommandStatus $status = AgentCommandStatus::Claimed): array
{
    $node = batchNode();
    $project = Project::factory()->create();
    $deployment = Deployment::factory()->for($project)->create([
        'sequence' => 1,
        'status' => DeploymentStatus::Building,
        'agent_node_id' => $node->id,
    ]);
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::DeployProject,
        'status' => $status,
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'payload' => [],
    ]);

    return compact('node', 'command', 'deployment');
}

function batchHeaders(AgentNode $node, string $key, string $token = 'batch-token'): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'X-Agent-Id' => $node->agent_id,
        'Idempotency-Key' => $key,
    ];
}

/**
 * The acknowledgement invariants the agent checks before treating a 200 as
 * delivered: every field present, accepted_count == count(items),
 * duplicate_count <= accepted_count, last_sequence >= first_sequence.
 */
function expectValidAcknowledgement(array $body, int $itemCount, int $duplicates = 0): void
{
    expect($body)->toHaveKey('data')
        ->and(array_keys($body['data']))->toBe(['accepted_count', 'duplicate_count', 'first_sequence', 'last_sequence'])
        ->and($body['data']['accepted_count'])->toBe($itemCount)
        ->and($body['data']['duplicate_count'])->toBe($duplicates)
        ->and($body['data']['duplicate_count'])->toBeLessThanOrEqual($body['data']['accepted_count'])
        ->and($body['data']['last_sequence'])->toBeGreaterThanOrEqual($body['data']['first_sequence'])
        ->and($body['data']['last_sequence'] - $body['data']['first_sequence'] + 1)->toBe($itemCount);
}

test('a v0.2.0 log batch is persisted in order and acknowledged in full', function (): void {
    ['node' => $node, 'command' => $command, 'deployment' => $deployment] = batchContext();
    $fixture = agentReportFixture('logs-batch');

    $body = $this->withHeaders(batchHeaders($node, (string) Str::uuid()))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", $fixture)
        ->assertOk()
        ->json();

    expectValidAcknowledgement($body, count($fixture['logs']));
    expect($body['data']['first_sequence'])->toBe(1);

    $stored = DeploymentLog::query()->where('deployment_id', $deployment->id)->orderBy('sequence')->get();
    expect($stored->pluck('message')->all())->toBe(array_column($fixture['logs'], 'message'))
        ->and($stored->pluck('stream')->map(fn ($s) => $s->value)->all())->toBe(array_column($fixture['logs'], 'stream'))
        ->and($command->fresh()->status)->toBe(AgentCommandStatus::Running)
        ->and($command->fresh()->reported_log_bytes)->toBe(array_sum(array_map('strlen', array_column($fixture['logs'], 'message'))));
});

test('a v0.2.0 event batch is persisted and drives the deployment phase', function (): void {
    ['node' => $node, 'command' => $command, 'deployment' => $deployment] = batchContext();
    $fixture = agentReportFixture('events-batch');

    $body = $this->withHeaders(batchHeaders($node, (string) Str::uuid()))
        ->postJson("/api/agent/v1/commands/{$command->id}/events", $fixture)
        ->assertOk()
        ->json();

    expectValidAcknowledgement($body, count($fixture['events']));

    $event = DeploymentEvent::query()->where('deployment_id', $deployment->id)->sole();
    expect($event->type)->toBe('deployment.runtime.ready')
        ->and($event->metadata)->toBe($fixture['events'][0]['metadata'])
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Routing);
});

test('retrying a batch with the same key acknowledges every item as a duplicate', function (): void {
    ['node' => $node, 'command' => $command, 'deployment' => $deployment] = batchContext();
    $fixture = agentReportFixture('logs-batch');
    $key = (string) Str::uuid();

    $first = $this->withHeaders(batchHeaders($node, $key))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", $fixture)
        ->assertOk()->json();

    // Transport retry after a lost response (the only case the agent retries a 200): same key, same body.
    $retry = $this->withHeaders(batchHeaders($node, $key))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", $fixture)
        ->assertOk()->json();

    expectValidAcknowledgement($retry, count($fixture['logs']), duplicates: count($fixture['logs']));
    expect($retry['data']['first_sequence'])->toBe($first['data']['first_sequence'])
        ->and($retry['data']['last_sequence'])->toBe($first['data']['last_sequence'])
        ->and(DeploymentLog::query()->where('deployment_id', $deployment->id)->count())->toBe(count($fixture['logs']));
});

test('a single-item retry reports accepted 1 and duplicate 1', function (): void {
    ['node' => $node, 'command' => $command] = batchContext();
    $key = (string) Str::uuid();
    $one = ['logs' => [agentReportFixture('logs-batch')['logs'][0]]];

    $this->withHeaders(batchHeaders($node, $key))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", $one)
        ->assertOk()
        ->assertExactJson(['data' => ['accepted_count' => 1, 'duplicate_count' => 0, 'first_sequence' => 1, 'last_sequence' => 1]]);

    $this->withHeaders(batchHeaders($node, $key))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", $one)
        ->assertOk()
        ->assertExactJson(['data' => ['accepted_count' => 1, 'duplicate_count' => 1, 'first_sequence' => 1, 'last_sequence' => 1]]);
});

test('reusing a key for a different batch is refused instead of acknowledged', function (): void {
    ['node' => $node, 'command' => $command] = batchContext();
    $key = (string) Str::uuid();
    $fixture = agentReportFixture('logs-batch');

    $this->withHeaders(batchHeaders($node, $key))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", $fixture)
        ->assertOk();

    $changed = $fixture;
    $changed['logs'][1]['message'] = '[docker-build] something else';

    $this->withHeaders(batchHeaders($node, $key))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", $changed)
        ->assertStatus(409);
});

test('an empty batch is rejected before any acknowledgement could be malformed', function (): void {
    ['node' => $node, 'command' => $command] = batchContext();

    $this->withHeaders(batchHeaders($node, (string) Str::uuid()))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", ['logs' => []])
        ->assertUnprocessable();

    $this->withHeaders(batchHeaders($node, (string) Str::uuid()))
        ->postJson("/api/agent/v1/commands/{$command->id}/events", ['events' => []])
        ->assertUnprocessable();
});

test('the follower keeps batching under the deploy command after it succeeded and stops on 422', function (): void {
    ['node' => $node, 'command' => $command] = batchContext(AgentCommandStatus::Succeeded);
    $command->update(['payload' => ['log_bounds' => ['max_total_bytes' => 80]]]);
    $fixture = agentReportFixture('logs-batch');

    $body = $this->withHeaders(batchHeaders($node, (string) Str::uuid()))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", $fixture)
        ->assertOk()->json();
    expectValidAcknowledgement($body, count($fixture['logs']));

    // Budget exhausted: the agent stops delivery for this command on 422.
    $this->withHeaders(batchHeaders($node, (string) Str::uuid()))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", $fixture)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['logs']);
});

test('a reconcile completion records which deploy command the restarted follower reports under', function (): void {
    $node = batchNode();
    $project = Project::factory()->create();
    $deployment = Deployment::factory()->for($project)->create([
        'sequence' => 1, 'status' => DeploymentStatus::Succeeded, 'agent_node_id' => $node->id,
    ]);
    $deploy = AgentCommand::factory()->create([
        'type' => AgentCommandType::DeployProject, 'status' => AgentCommandStatus::Succeeded,
        'project_id' => $project->id, 'deployment_id' => $deployment->id, 'agent_node_id' => $node->id, 'payload' => [],
    ]);
    $reconcile = AgentCommand::factory()->create([
        'type' => AgentCommandType::ReconcileWorkload, 'status' => AgentCommandStatus::Running,
        'project_id' => $project->id, 'deployment_id' => $deployment->id, 'agent_node_id' => $node->id,
        'payload' => ['desired_state' => 'running', 'actions' => ['restart_log_follower']],
    ]);

    $this->withHeaders(batchHeaders($node, (string) Str::uuid()))
        ->postJson("/api/agent/v1/commands/{$reconcile->id}/complete", ['result' => [
            'desired_state' => 'running', 'actual_state' => 'running', 'in_sync' => true, 'drift_reason' => null,
            'container_id' => 'abc',
            'actions_applied' => [['action' => 'restart_log_follower', 'started' => true, 'command_id' => $deploy->id]],
        ]])
        ->assertNoContent();

    $audit = AuditEvent::query()->where('action', 'project.reconcile_completed')->sole();
    expect($audit->metadata['log_follower_command_ids'])->toBe([$deploy->id]);

    // The restarted follower reports under the original deploy command.
    $this->withHeaders(batchHeaders($node, (string) Str::uuid()))
        ->postJson("/api/agent/v1/commands/{$deploy->id}/logs", ['logs' => [
            ['stream' => 'stdout', 'message' => 'GET / 200', 'recorded_at' => now()->toIso8601String()],
        ]])
        ->assertOk()
        ->assertJsonPath('data.accepted_count', 1);
});
