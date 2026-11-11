<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandReportKind;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\LogStream;
use App\Models\AgentCommand;
use App\Models\AgentCommandReport;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = adminApiAdmin();
});

test('admins list nodes with version, capabilities, workload counts, and filters', function (): void {
    $node = AgentNode::factory()->create([
        'name' => 'agent-jkt-01',
        'hostname' => 'runtime-1',
        'runtime_network' => 'sakala-runtime',
        'capabilities' => ['docker', 'caddy', 'railpack'],
        'metadata' => ['version' => '0.5.0', 'protocol_version' => 4],
        'last_seen_at' => now(),
    ]);
    AgentNode::factory()->create(['name' => 'agent-baru', 'status' => AgentNodeStatus::Offline, 'last_seen_at' => null]);

    $project = Project::factory()->create();
    $serving = Deployment::factory()->for($project)->create(['sequence' => 1, 'status' => DeploymentStatus::Succeeded, 'agent_node_id' => $node->id]);
    Deployment::factory()->for($project)->create(['sequence' => 2, 'status' => DeploymentStatus::Failed, 'agent_node_id' => $node->id]);
    $project->update(['active_deployment_id' => $serving->id]);
    AgentCommand::factory()->create(['agent_node_id' => $node->id, 'status' => AgentCommandStatus::Running]);
    AgentCommand::factory()->create(['agent_node_id' => $node->id, 'status' => AgentCommandStatus::Succeeded]);

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/agents?search=jkt')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.agent_version', '0.5.0')
        ->assertJsonPath('data.0.hostname', 'runtime-1')
        ->assertJsonPath('data.0.runtime_network', 'sakala-runtime')
        ->assertJsonPath('data.0.capabilities', ['docker', 'caddy', 'railpack'])
        ->assertJsonPath('data.0.active_workload_count', 1)
        ->assertJsonPath('data.0.open_commands_count', 1)
        ->assertJsonMissingPath('data.0.token_hash');

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/agents?never_seen=1')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'agent-baru');
    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/agents?status=offline')->assertJsonCount(1, 'data');
    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/agents?auth_status=revoked')->assertJsonCount(0, 'data');

    $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/agents/{$node->id}")
        ->assertOk()
        ->assertJsonPath('data.active_workload_count', 1);
});

test('node command history lists commands without payloads, and a command shows its reports', function (): void {
    $node = AgentNode::factory()->create();
    $other = AgentNode::factory()->create();
    $project = Project::factory()->create(['name' => 'Toko Kue']);
    $cleanup = AgentCommand::factory()->create([
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::CleanupRuntime,
        'status' => AgentCommandStatus::Failed,
        'payload' => ['secret' => 'payload-secret'],
        'error_code' => 'runtime_disk_pressure',
        'error_message' => 'disk full',
        'claimed_at' => now()->subMinutes(2),
        'failed_at' => now(),
        'created_at' => now()->subMinutes(3),
    ]);
    AgentCommand::factory()->create(['agent_node_id' => $node->id, 'project_id' => $project->id, 'type' => AgentCommandType::StopProject]);
    $foreign = AgentCommand::factory()->create(['agent_node_id' => $other->id]);
    foreach ([1, 2] as $sequence) {
        AgentCommandReport::query()->create([
            'agent_command_id' => $cleanup->id, 'sequence' => $sequence, 'kind' => AgentCommandReportKind::Log,
            'stream' => LogStream::Stderr, 'message' => "line {$sequence}", 'occurred_at' => now(),
        ]);
    }

    $list = $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/agents/{$node->id}/commands")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'StopProject')
        ->assertJsonPath('data.0.project.name', 'Toko Kue')
        ->assertJsonPath('data.1.type', 'CleanupRuntime')
        ->assertJsonPath('data.1.error_code', 'runtime_disk_pressure')
        ->assertJsonPath('data.1.duration_seconds', 120)
        ->assertJsonPath('data.1.reports_count', 2)
        ->assertJsonMissingPath('data.1.reports');

    expect($list->getContent())->not->toContain('payload-secret');

    $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/agents/{$node->id}/commands?type=CleanupRuntime&status=Failed")
        ->assertJsonCount(1, 'data');

    $detail = $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/agents/{$node->id}/commands/{$cleanup->id}")
        ->assertOk()
        ->assertJsonPath('data.reports.0.message', 'line 1')
        ->assertJsonPath('data.reports.1.message', 'line 2')
        ->assertJsonPath('data.reports.1.stream', 'stderr');

    expect($detail->getContent())->not->toContain('payload-secret');

    $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/agents/{$node->id}/commands/{$foreign->id}")->assertNotFound();
});

test('provisioning a node returns its token once, stores only the hash, and is audited', function (): void {
    $response = $this->actingAs($this->admin, 'web')->postJson('/api/v1/admin/agents', [
        'name' => 'agent-jkt-04',
        'description' => 'Node cadangan',
        'reason' => 'Tambah kapasitas',
    ])->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.agent.name', 'agent-jkt-04')
        ->assertJsonPath('data.agent.auth_status', 'active');

    $token = $response->json('data.token');
    $node = AgentNode::query()->findOrFail($response->json('data.agent.id'));

    expect($token)->toBeString()->toHaveLength(64)
        ->and($node->getAttributes()['token_hash'])->toBe(hash_hmac('sha256', $token, (string) config('app.key')))
        ->and($node->getAttributes()['token_hash'])->not->toBe($token);

    $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/agents/{$node->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.token');

    $audit = AuditEvent::query()->where('action', 'agent.node.provisioned')->sole();
    expect($audit->subject_id)->toBe($node->id)
        ->and($audit->metadata['reason'])->toBe('Tambah kapasitas')
        ->and(json_encode($audit->metadata))->not->toContain($token);

    $this->actingAs($this->admin, 'web')->postJson('/api/v1/admin/agents', ['name' => 'tanpa-alasan'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');
});

test('rotating a token replaces it, and revoking is audited once', function (): void {
    $node = AgentNode::factory()->create();
    $oldHash = $node->getAttributes()['token_hash'];

    $token = $this->actingAs($this->admin, 'web')
        ->postJson("/api/v1/admin/agents/{$node->id}/rotate", ['reason' => 'Token bocor'])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->json('data.token');

    expect($node->refresh()->getAttributes()['token_hash'])->toBe(hash_hmac('sha256', $token, (string) config('app.key')))->not->toBe($oldHash);

    foreach (['Node dibongkar', 'Ulang'] as $reason) {
        $this->actingAs($this->admin, 'web')
            ->postJson("/api/v1/admin/agents/{$node->id}/revoke", ['reason' => $reason])
            ->assertOk()
            ->assertJsonPath('data.auth_status', 'revoked');
    }

    expect($node->refresh()->auth_status)->toBe(AgentAuthStatus::Revoked)
        ->and(AuditEvent::query()->where('action', 'agent.node.revoked')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'agent.node.token_rotated')->sole()->metadata['reason'])->toBe('Token bocor');
});
