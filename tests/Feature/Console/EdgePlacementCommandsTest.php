<?php

declare(strict_types=1);

use App\Actions\Admin\UpdateAgentNodePlacementAction;
use App\Actions\Edge\ManageEdgeTokenAction;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\EdgeToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/** Runs a command without a terminal and returns its exit code and output. */
function runQuietly(string $command, array $arguments): array
{
    $exitCode = Artisan::call($command, [...$arguments, '--no-interaction' => true]);

    return [$exitCode, Artisan::output()];
}

function printedEdgeToken(string $output): string
{
    expect($output)->toMatch('/^SAKALA_EDGE_TOKEN=[A-Za-z0-9]{'.EdgeToken::TOKEN_LENGTH.'}$/m');
    preg_match('/^SAKALA_EDGE_TOKEN=(\S+)$/m', $output, $matches);

    return $matches[1];
}

// ─── edge:token ──────────────────────────────────────────────────────────────

test('edge:token prints a token once and stores only its hash', function (): void {
    [$exitCode, $output] = runQuietly('edge:token', ['name' => 'edge-jkt-1', '--reason' => 'Edge staging']);

    $token = printedEdgeToken($output);
    $record = EdgeToken::query()->sole();

    expect($exitCode)->toBe(0)
        ->and($record->name)->toBe('edge-jkt-1')
        ->and($record->token_hash)->toBe(EdgeToken::hash($token))
        ->and($record->token_hash)->not->toContain($token)
        ->and($record->token_prefix)->toBe(substr($token, 0, EdgeToken::PREFIX_LENGTH))
        ->and($record->toArray())->not->toHaveKey('token_hash');

    $audit = AuditEvent::query()->where('action', ManageEdgeTokenAction::ISSUED_AUDIT_ACTION)->sole();
    expect(json_encode($audit->metadata))->not->toContain($token)
        ->and($audit->metadata['reason'])->toBe('Edge staging');

    $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson('/api/edge/v1/routes')->assertOk();
});

test('running edge:token again rotates the token and stops the old one', function (): void {
    $old = printedEdgeToken(runQuietly('edge:token', ['name' => 'edge-jkt-1', '--reason' => 'awal'])[1]);
    $new = printedEdgeToken(runQuietly('edge:token', ['name' => 'edge-jkt-1', '--reason' => 'rotasi'])[1]);

    expect($new)->not->toBe($old)
        ->and(EdgeToken::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', ManageEdgeTokenAction::ROTATED_AUDIT_ACTION)->exists())->toBeTrue();

    $this->withHeaders(['Authorization' => "Bearer {$old}"])->getJson('/api/edge/v1/routes')->assertUnauthorized();
    $this->withHeaders(['Authorization' => "Bearer {$new}"])->getJson('/api/edge/v1/routes')->assertOk();
});

test('edge:token --revoke refuses the token from then on', function (): void {
    $token = printedEdgeToken(runQuietly('edge:token', ['name' => 'edge-jkt-1', '--reason' => 'awal'])[1]);

    [$exitCode] = runQuietly('edge:token', ['name' => 'edge-jkt-1', '--revoke' => true, '--reason' => 'host dimatikan']);

    expect($exitCode)->toBe(0)
        ->and(EdgeToken::query()->sole()->isRevoked())->toBeTrue()
        ->and(AuditEvent::query()->where('action', ManageEdgeTokenAction::REVOKED_AUDIT_ACTION)->count())->toBe(1);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson('/api/edge/v1/routes')->assertForbidden();
});

test('revoking an unknown token fails without changes', function (): void {
    [$exitCode] = runQuietly('edge:token', ['name' => 'missing', '--revoke' => true, '--reason' => 'x']);

    expect($exitCode)->toBe(1)
        ->and(AuditEvent::query()->exists())->toBeFalse();
});

test('a terminal run asks for a reason', function (): void {
    $this->artisan('edge:token', ['name' => 'edge-jkt-1'])
        ->expectsQuestion('Reason for this change (stored in the audit log)', '')
        ->assertFailed();

    expect(EdgeToken::query()->exists())->toBeFalse();
});

// ─── agent:node-edge-address and agent:node-region ───────────────────────────

test('an admin sets and clears the edge address of a node', function (): void {
    $node = AgentNode::factory()->create(['name' => 'runtime-01', 'metadata' => ['runtime' => ['edge_address' => '10.0.47.201:8180']]]);

    [$exitCode, $output] = runQuietly('agent:node-edge-address', ['node' => 'runtime-01', 'address' => '172.16.0.5:8180', '--reason' => 'WireGuard']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('172.16.0.5:8180')
        ->and($node->fresh()->edge_address_override)->toBe('172.16.0.5:8180')
        ->and($node->fresh()->effectiveEdgeAddress())->toBe('172.16.0.5:8180');

    $audit = AuditEvent::query()->where('action', UpdateAgentNodePlacementAction::EDGE_ADDRESS_AUDIT_ACTION)->sole();
    expect($audit->metadata['from'])->toBeNull()
        ->and($audit->metadata['to'])->toBe('172.16.0.5:8180')
        ->and($audit->subject_id)->toBe($node->id);

    runQuietly('agent:node-edge-address', ['node' => $node->agent_id, '--clear' => true, '--reason' => 'pakai laporan node']);

    expect($node->fresh()->edge_address_override)->toBeNull()
        ->and($node->fresh()->effectiveEdgeAddress())->toBe('10.0.47.201:8180');
});

test('an admin sets and clears the region of a node', function (): void {
    $node = AgentNode::factory()->create(['metadata' => ['runtime' => ['region' => 'jkt']]]);

    runQuietly('agent:node-region', ['node' => $node->id, 'region' => 'jog', '--reason' => 'pindah rak']);

    expect($node->fresh()->effectiveRegion())->toBe('jog')
        ->and(AuditEvent::query()->where('action', UpdateAgentNodePlacementAction::REGION_AUDIT_ACTION)->count())->toBe(1);

    runQuietly('agent:node-region', ['node' => $node->id, '--clear' => true, '--reason' => 'kembali']);

    expect($node->fresh()->effectiveRegion())->toBe('jkt');
});

test('placement commands reject bad input without changes', function (string $command, array $arguments): void {
    AgentNode::factory()->create(['name' => 'runtime-01']);
    AgentNode::factory()->create(['name' => 'twin']);
    AgentNode::factory()->create(['name' => 'twin']);

    [$exitCode] = runQuietly($command, [...$arguments, '--reason' => 'x']);

    expect($exitCode)->toBe(1)
        ->and(AgentNode::query()->whereNotNull('edge_address_override')->orWhereNotNull('region_override')->exists())->toBeFalse()
        ->and(AuditEvent::query()->exists())->toBeFalse();
})->with([
    'address with scheme' => ['agent:node-edge-address', ['node' => 'runtime-01', 'address' => 'http://10.0.0.1:8180']],
    'address and clear' => ['agent:node-edge-address', ['node' => 'runtime-01', 'address' => '10.0.0.1:8180', '--clear' => true]],
    'neither address nor clear' => ['agent:node-edge-address', ['node' => 'runtime-01']],
    'unknown node' => ['agent:node-edge-address', ['node' => 'nope', 'address' => '10.0.0.1:8180']],
    'ambiguous name' => ['agent:node-region', ['node' => 'twin', 'region' => 'jkt']],
    'malformed region' => ['agent:node-region', ['node' => 'runtime-01', 'region' => 'Jakarta']],
]);

// ─── Admin node resources ────────────────────────────────────────────────────

test('admin node resources show region, edge address, and capacity', function (): void {
    $node = AgentNode::factory()->create([
        'region_override' => 'jog',
        'metadata' => [
            'runtime' => ['region' => 'jkt', 'edge_address' => '10.0.47.201:8180'],
            'resources' => ['memory_total_bytes' => 8589934592, 'memory_available_bytes' => 4294967296],
            'workloads' => ['active' => 2, 'maximum_active_workloads' => 5],
        ],
    ]);
    $admin = adminApiAdmin();

    $this->actingAs($admin, 'web')
        ->getJson("/api/v1/admin/agents/{$node->id}")
        ->assertOk()
        ->assertJsonPath('data.region', 'jog')
        ->assertJsonPath('data.region_override', 'jog')
        ->assertJsonPath('data.reported_region', 'jkt')
        ->assertJsonPath('data.edge_address', '10.0.47.201:8180')
        ->assertJsonPath('data.edge_address_override', null)
        ->assertJsonPath('data.reported_edge_address', '10.0.47.201:8180')
        ->assertJsonPath('data.capacity', [
            'memory_total_bytes' => 8589934592,
            'memory_available_bytes' => 4294967296,
            'active_workloads' => 2,
            'maximum_active_workloads' => 5,
            'free_workload_slots' => 3,
        ]);

    $this->actingAs($admin, 'web')
        ->getJson("/api/agent/v1/agents/{$node->id}")
        ->assertOk()
        ->assertJsonPath('data.region', 'jog')
        ->assertJsonPath('data.edge_address', '10.0.47.201:8180');
});

test('a node that reports nothing shows nulls', function (): void {
    $node = AgentNode::factory()->create(['metadata' => null]);

    $this->actingAs(adminApiAdmin(), 'web')
        ->getJson("/api/v1/admin/agents/{$node->id}")
        ->assertOk()
        ->assertJsonPath('data.region', null)
        ->assertJsonPath('data.edge_address', null)
        ->assertJsonPath('data.capacity.free_workload_slots', null);
});
