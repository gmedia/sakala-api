<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandReportKind;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Models\AgentCommand;
use App\Models\AgentCommandReport;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

const V4_CAPABILITIES = [
    'docker-runtime',
    'project-inspection',
    'dockerfile-build',
    'railpack-info',
    'railpack-build',
    'caddy-file-routing',
];

function v4Agent(string $token, array $overrides = []): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'protocol_version' => 4,
        'desired_state' => AgentNodeDesiredState::Active,
        'capabilities' => V4_CAPABILITIES,
        'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
        ...$overrides,
    ]);
}

function v4Headers(AgentNode $agent, string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'X-Agent-Id' => $agent->agent_id,
    ];
}

/**
 * Persist a fixture command, replacing fixture identifiers with real rows so
 * foreign keys hold. Returns the stored command and the fixture it came from.
 *
 * @return array{command: AgentCommand, fixture: array<string, mixed>}
 */
function storeFixtureCommand(string $name, ?AgentNode $pinnedTo = null): array
{
    $fixture = agentCommandFixture($name);
    $type = AgentCommandType::from($fixture['type']);

    $project = $fixture['project_id'] === null ? null : Project::factory()->create();
    $deployment = $fixture['deployment_id'] === null || $project === null
        ? null
        : Deployment::factory()->for($project)->create(['sequence' => 1]);

    $stored = $fixture['payload'];

    // The API keeps environment values encrypted at rest and only decrypts
    // them on the wire for the pinned node, so store ciphertext here.
    if ($type === AgentCommandType::DeployProject && isset($stored['environment'])) {
        $stored['environment'] = array_map(
            static fn (string $value): string => Crypt::encryptString($value),
            $stored['environment'],
        );
    }

    $command = AgentCommand::factory()->create([
        'type' => $type,
        'status' => AgentCommandStatus::from($fixture['status']),
        'project_id' => $project?->id,
        'deployment_id' => $deployment?->id,
        'agent_node_id' => $pinnedTo?->id,
        'payload' => $stored,
    ]);

    return ['command' => $command, 'fixture' => $fixture];
}

// ─── Protocol fixtures ───────────────────────────────────────────────────────

test('poll serialises every v0.2.0 command fixture exactly as the agent expects', function (string $name): void {
    $agent = v4Agent('fixture-token');
    ['command' => $command, 'fixture' => $fixture] = storeFixtureCommand($name, $agent);

    $response = $this->withHeaders(v4Headers($agent, 'fixture-token'))
        ->getJson('/api/agent/v1/commands')
        ->assertOk();

    $items = $response->json('data');
    expect($items)->toHaveCount(1);

    $item = $items[0];
    expect($item['id'])->toBe($command->id)
        ->and($item['type'])->toBe($fixture['type'])
        ->and($item['status'])->toBe('Pending')
        ->and($item['project_id'])->toBe($command->project_id)
        ->and($item['deployment_id'])->toBe($command->deployment_id)
        ->and($item['payload'])->toBe($fixture['payload']);

    // Empty payloads must be a JSON object, never an array, on the wire.
    if ($fixture['payload'] === []) {
        expect($response->getContent())->toContain('"payload":{}');
    }
})->with(['cleanup-runtime', 'deploy-project', 'inspect-project', 'reconcile-workload', 'restart-project', 'stop-project']);

test('heartbeat accepts the v0.2.0 wire payload verbatim', function (string $name): void {
    $agent = v4Agent('hb-fixture-token', ['protocol_version' => null, 'capabilities' => []]);
    $fixture = agentHeartbeatFixture($name);

    $this->withHeaders(v4Headers($agent, 'hb-fixture-token'))
        ->postJson('/api/agent/v1/heartbeat', $fixture)
        ->assertOk();

    $agent->refresh();
    expect($agent->protocol_version)->toBe(4)
        ->and($agent->status->value)->toBe($fixture['status'])
        ->and($agent->capabilities)->toBe($fixture['capabilities'])
        ->and($agent->hostname)->toBe($fixture['hostname'])
        ->and($agent->last_seen_at)->not->toBeNull();
})->with(['docker-ready', 'docker-recovered', 'noop-degraded']);

test('heartbeat detail counts are optional but must be complete when sent', function (): void {
    $agent = v4Agent('hb-counts-token');

    // A v0.1.0 agent never sends detail_counts; v0.2.0 always does.
    $withoutCounts = agentHeartbeatFixture('docker-ready');
    unset($withoutCounts['metadata']['detail_counts']);

    $this->withHeaders(v4Headers($agent, 'hb-counts-token'))
        ->postJson('/api/agent/v1/heartbeat', $withoutCounts)
        ->assertOk();

    $partial = $withoutCounts;
    $partial['metadata']['detail_counts'] = ['orphans' => 1];

    $this->withHeaders(v4Headers($agent, 'hb-counts-token'))
        ->postJson('/api/agent/v1/heartbeat', $partial)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['metadata.detail_counts.unhealthy_details']);
});

// ─── Protocol revision gate ──────────────────────────────────────────────────

test('heartbeat persists the reported protocol revision as a queryable column', function (): void {
    $agent = v4Agent('hb-token', ['protocol_version' => null]);

    $this->withHeaders(v4Headers($agent, 'hb-token'))
        ->postJson('/api/agent/v1/heartbeat', heartbeatPayload())
        ->assertOk();

    expect($agent->fresh()->protocol_version)->toBe(4);
});

test('a node on an unsupported protocol revision stays online but receives no commands', function (): void {
    $agent = v4Agent('legacy-token');
    AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
        'agent_node_id' => $agent->id,
    ]);

    $this->withHeaders(v4Headers($agent, 'legacy-token'))
        ->postJson('/api/agent/v1/heartbeat', heartbeatPayload([
            'metadata' => ['protocol_version' => 3],
        ]))
        ->assertOk();

    expect($agent->fresh()->protocol_version)->toBe(3)
        ->and($agent->fresh()->status)->toBe(AgentNodeStatus::Ready);

    $this->withHeaders(v4Headers($agent, 'legacy-token'))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

test('a node that has never heartbeated is not offered commands', function (): void {
    $agent = v4Agent('unseen-token', ['protocol_version' => null]);
    AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
    ]);

    $this->withHeaders(v4Headers($agent, 'unseen-token'))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

test('claim conflicts when the node protocol became unsupported between poll and claim', function (): void {
    $agent = v4Agent('gate-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
    ]);

    $agent->update(['protocol_version' => 3]);

    $this->withHeaders(v4Headers($agent, 'gate-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/claim", [])
        ->assertStatus(409)
        ->assertJsonPath('status', 'Pending');

    expect($command->fresh()->status)->toBe(AgentCommandStatus::Pending);
});

// ─── Desired lifecycle state ─────────────────────────────────────────────────

test('a draining node only sees its own lifecycle commands', function (): void {
    $agent = v4Agent('drain-token', ['desired_state' => AgentNodeDesiredState::Draining]);
    $other = v4Agent('other-token', ['desired_state' => AgentNodeDesiredState::Draining]);

    $ownDrain = AgentCommand::factory()->create([
        'type' => AgentCommandType::DrainNode,
        'status' => AgentCommandStatus::Pending,
        'agent_node_id' => $agent->id,
    ]);
    AgentCommand::factory()->create([
        'type' => AgentCommandType::DrainNode,
        'status' => AgentCommandStatus::Pending,
        'agent_node_id' => $other->id,
    ]);
    AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
        'agent_node_id' => $agent->id,
    ]);

    $this->withHeaders(v4Headers($agent, 'drain-token'))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownDrain->id)
        ->assertJsonPath('data.0.type', 'DrainNode');
});

test('a drained node cannot claim workload commands', function (): void {
    $agent = v4Agent('drained-token', ['desired_state' => AgentNodeDesiredState::Drained]);
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
        'agent_node_id' => $agent->id,
    ]);

    $this->withHeaders(v4Headers($agent, 'drained-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/claim", [])
        ->assertStatus(409);
});

test('an unassigned pinned command is offered to nobody until the control plane assigns it', function (): void {
    $agent = v4Agent('pin-token');
    AgentCommand::factory()->create([
        'type' => AgentCommandType::DrainNode,
        'status' => AgentCommandStatus::Pending,
        'agent_node_id' => null,
    ]);

    $this->withHeaders(v4Headers($agent, 'pin-token'))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

test('reconcile workload is withheld from and unclaimable on a suspended project', function (): void {
    $agent = v4Agent('suspend-token');
    $project = Project::factory()->create(['status' => ProjectStatus::Suspended]);
    // Reconcile only ever targets the current succeeded deployment.
    $deployment = Deployment::factory()->for($project)->create([
        'sequence' => 1,
        'status' => DeploymentStatus::Succeeded,
        'agent_node_id' => $agent->id,
    ]);
    $reconcile = AgentCommand::factory()->create([
        'type' => AgentCommandType::ReconcileWorkload,
        'status' => AgentCommandStatus::Pending,
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $agent->id,
        'payload' => ['desired_state' => 'running', 'actions' => ['restore_route']],
    ]);
    $stop = AgentCommand::factory()->create([
        'type' => AgentCommandType::StopProject,
        'status' => AgentCommandStatus::Pending,
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $agent->id,
    ]);

    $this->withHeaders(v4Headers($agent, 'suspend-token'))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $stop->id);

    $this->withHeaders(v4Headers($agent, 'suspend-token'))
        ->postJson("/api/agent/v1/commands/{$reconcile->id}/claim", [])
        ->assertStatus(409)
        ->assertJsonPath('status', 'Pending');

    expect($reconcile->fresh()->status)->toBe(AgentCommandStatus::Pending)
        ->and($reconcile->fresh()->claimed_at)->toBeNull();

    // Lifting the suspension makes the same command claimable again.
    $project->update(['status' => ProjectStatus::Active]);

    $this->withHeaders(v4Headers($agent, 'suspend-token'))
        ->postJson("/api/agent/v1/commands/{$reconcile->id}/claim", [])
        ->assertOk();
});

// ─── Claimed -> Running ──────────────────────────────────────────────────────

test('the first accepted report from the owner moves a claimed command to running', function (): void {
    $agent = v4Agent('run-token');
    $project = Project::factory()->create();
    $deployment = Deployment::factory()->for($project)->create(['sequence' => 1]);
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Claimed,
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $agent->id,
        'payload' => [],
    ]);

    $this->withHeaders(v4Headers($agent, 'run-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/events", [
            'type' => 'command.claimed',
            'level' => 'info',
            'message' => 'Agent claimed command.',
            'metadata' => [],
            'occurred_at' => '2026-09-14T10:00:00Z',
        ])
        ->assertOk();

    $command->refresh();
    expect($command->status)->toBe(AgentCommandStatus::Running)
        ->and($command->started_at)->not->toBeNull();

    $startedAt = $command->started_at;

    $this->withHeaders(v4Headers($agent, 'run-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", [
            'stream' => 'stdout',
            'message' => '[checkout] Cloning repository',
            'recorded_at' => '2026-09-14T10:00:01Z',
        ])
        ->assertOk();

    expect($command->fresh()->started_at?->equalTo($startedAt))->toBeTrue();
});

test('a report from another node does not start the command', function (): void {
    $agent = v4Agent('owner-token');
    $intruder = v4Agent('intruder-token');
    $project = Project::factory()->create();
    $deployment = Deployment::factory()->for($project)->create(['sequence' => 1]);
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Claimed,
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $agent->id,
        'payload' => [],
    ]);

    $this->withHeaders(v4Headers($intruder, 'intruder-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/events", [
            'type' => 'command.claimed',
            'level' => 'info',
            'message' => 'Agent claimed command.',
            'metadata' => [],
            'occurred_at' => '2026-09-14T10:00:00Z',
        ])
        ->assertStatus(409)
        ->assertJsonPath('status', 'Claimed');

    expect($command->fresh()->status)->toBe(AgentCommandStatus::Claimed)
        ->and($command->fresh()->started_at)->toBeNull();
});

// ─── Reports for commands without a deployment ───────────────────────────────

test('node-level commands accept the claimed event and persist it against the command', function (): void {
    $agent = v4Agent('node-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::DrainNode,
        'status' => AgentCommandStatus::Claimed,
        'agent_node_id' => $agent->id,
    ]);

    $this->withHeaders(v4Headers($agent, 'node-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/events", [
            'type' => 'command.claimed',
            'level' => 'info',
            'message' => 'Agent claimed command.',
            'metadata' => [],
            'occurred_at' => '2026-09-14T10:00:00Z',
        ])
        ->assertOk()
        ->assertExactJson(['data' => [
            'accepted_count' => 1,
            'duplicate_count' => 0,
            'first_sequence' => 1,
            'last_sequence' => 1,
        ]]);

    $report = AgentCommandReport::query()->sole();
    expect($report->agent_command_id)->toBe($command->id)
        ->and($report->kind)->toBe(AgentCommandReportKind::Event)
        ->and($report->type)->toBe('command.claimed')
        ->and($report->sequence)->toBe(1)
        ->and($command->fresh()->status)->toBe(AgentCommandStatus::Running);
});

test('inspection commands stream logs without a deployment and honour the log budget', function (): void {
    $agent = v4Agent('inspect-token');
    $project = Project::factory()->create();
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::InspectProject,
        'status' => AgentCommandStatus::Running,
        'project_id' => $project->id,
        'deployment_id' => null,
        'agent_node_id' => $agent->id,
        'payload' => ['log_bounds' => ['max_total_bytes' => 40]],
    ]);

    $this->withHeaders(v4Headers($agent, 'inspect-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", [
            'stream' => 'stdout',
            'message' => '[railpack] detected node',
            'recorded_at' => '2026-09-14T10:00:00Z',
        ])
        ->assertOk()
        ->assertJsonPath('data.first_sequence', 1);

    expect($command->fresh()->reported_log_bytes)->toBe(strlen('[railpack] detected node'));

    $this->withHeaders(v4Headers($agent, 'inspect-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", [
            'stream' => 'stdout',
            'message' => str_repeat('x', 30),
            'recorded_at' => '2026-09-14T10:00:01Z',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['logs']);
});

test('identical retries on a finished node-level command are acknowledged as duplicates', function (): void {
    $agent = v4Agent('retry-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::ResumeNode,
        'status' => AgentCommandStatus::Claimed,
        'agent_node_id' => $agent->id,
    ]);

    $body = [
        'type' => 'node.resume.completed',
        'level' => 'info',
        'message' => 'Node resumed.',
        'metadata' => [],
        'occurred_at' => '2026-09-14T10:00:00Z',
    ];

    $this->withHeaders([...v4Headers($agent, 'retry-token'), 'Idempotency-Key' => 'resume-1'])
        ->postJson("/api/agent/v1/commands/{$command->id}/events", $body)
        ->assertOk();

    $this->withHeaders(v4Headers($agent, 'retry-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => ['state' => 'active']])
        ->assertNoContent();

    $this->withHeaders([...v4Headers($agent, 'retry-token'), 'Idempotency-Key' => 'resume-1'])
        ->postJson("/api/agent/v1/commands/{$command->id}/events", $body)
        ->assertOk()
        ->assertJsonPath('data.duplicate_count', 1)
        ->assertJsonPath('data.first_sequence', 1);

    $this->withHeaders([...v4Headers($agent, 'retry-token'), 'Idempotency-Key' => 'resume-2'])
        ->postJson("/api/agent/v1/commands/{$command->id}/events", [...$body, 'message' => 'New report.'])
        ->assertStatus(409)
        ->assertJsonPath('status', 'Succeeded');

    expect(AgentCommandReport::query()->count())->toBe(1);
});

test('completing a node-level command records an audit event without echoing the payload', function (): void {
    $agent = v4Agent('audit-token');
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::CleanupRuntime,
        'status' => AgentCommandStatus::Running,
        'agent_node_id' => $agent->id,
        'payload' => ['approved' => true, 'targets' => ['stale_images']],
    ]);

    $this->withHeaders(v4Headers($agent, 'audit-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", [
            'result' => ['approved' => true, 'cleaned_workspaces' => 0, 'cleaned_routes' => 0, 'reclaimed_image_bytes' => 1024],
        ])
        ->assertNoContent();

    $audit = AuditEvent::query()->where('action', 'agent.command.completed')->sole();
    expect($audit->subject_id)->toBe($command->id)
        ->and($audit->metadata['type'])->toBe('CleanupRuntime')
        ->and($audit->metadata['result_keys'])->toBe(['approved', 'cleaned_workspaces', 'cleaned_routes', 'reclaimed_image_bytes'])
        ->and($audit->metadata)->not->toHaveKey('payload')
        ->and($audit->metadata)->not->toHaveKey('targets');

    $this->withHeaders(v4Headers($agent, 'audit-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();

    expect(AuditEvent::query()->where('action', 'agent.command.completed')->count())->toBe(1);
});

test('stale route items are validated with their optional deployment id', function (): void {
    $agent = v4Agent('stale-route-token');

    $invalid = heartbeatPayload();
    $invalid['metadata']['startup_reconciliation']['stale_routes'][0]['deployment_id'] = 'not-a-uuid';

    $this->withHeaders(v4Headers($agent, 'stale-route-token'))
        ->postJson('/api/agent/v1/heartbeat', $invalid)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['metadata.startup_reconciliation.stale_routes.0.deployment_id']);

    // v0.1.0 items have no deployment_id at all; v0.2.0 sends null for legacy routes.
    $legacy = heartbeatPayload();
    unset($legacy['metadata']['startup_reconciliation']['stale_routes'][0]['deployment_id']);

    $this->withHeaders(v4Headers($agent, 'stale-route-token'))
        ->postJson('/api/agent/v1/heartbeat', $legacy)
        ->assertOk();

    $stored = $agent->fresh()->metadata['startup_reconciliation']['stale_routes'];
    expect($stored[0])->not->toHaveKey('deployment_id')
        ->and($stored[1]['deployment_id'])->toBeNull();
});
