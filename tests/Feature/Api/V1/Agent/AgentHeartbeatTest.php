<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentNodeStatus;
use App\Models\AgentNode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function heartbeatPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'status' => 'ready',
        'hostname' => 'runtime-01',
        'runtime_network' => 'sakala-runtime',

        'capabilities' => [
            'docker-runtime',
            'project-inspection',
        ],

        'metadata' => [
            'version' => '0.1.0',
            'protocol_version' => 4,
            'runtime_driver' => 'docker',
            'lifecycle_state' => 'active',
            'uptime_seconds' => 86400,

            'resources' => [
                'cpu_total' => 4,
                'cpu_load_1m' => 0.42,
                'memory_total_bytes' => 8589934592,
                'memory_available_bytes' => 4294967296,
                'disk_total_bytes' => 107374182400,
                'disk_available_bytes' => 53687091200,
                'workspace_used_bytes' => 104857600,
            ],

            'workloads' => [
                'active' => 2,
                'starting' => 0,
                'unhealthy' => 0,
                'stopped' => 1,
                'unhealthy_details' => [],
            ],

            'disk_pressure' => [
                'state' => 'normal',
                'minimum_workspace_free_bytes' => 2147483648,
                'available_workspace_bytes' => 53687091200,
            ],

            'runtime_dependencies' => [
                'git' => 'git version 2.47.0',
                'docker' => '27.3.1',
                'buildx' => 'github.com/docker/buildx v0.17.1',
                'railpack' => 'railpack 0.23.0',
            ],

            'execution' => [
                'active_commands' => 1,
                'queued_local_commands' => 0,
                'capacity_waiting_commands' => 1,
                'active_builds' => 1,
                'maximum_concurrent_builds' => 2,
            ],

            'startup_reconciliation' => [
                'captured_at' => '2026-06-23T07:59:58Z',
                'inspected_containers' => 2,
                'cleaned_workspaces' => 0,
                'reattached_log_followers' => 1,
                'recovered_execution_records' => 2,
                'recovered_workloads' => [],
                'orphans' => [],
                'stale_routes' => [],
                'stale_images' => [],
            ],
        ],

        'sent_at' => '2026-06-23T08:00:00Z',
    ], $overrides);
}

function activeAgent(string $token = 'agent-secret-token'): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'token_hash' => hash_hmac(
            'sha256',
            $token,
            (string) config('app.key'),
        ),
    ]);
}

function agentTokenHeaders(AgentNode $agent, string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'X-Agent-Id' => $agent->agent_id,
    ];
}

test('agent can send a valid heartbeat', function (): void {
    $token = 'agent-secret-token';

    $agent = AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Offline,
        'token_hash' => hash_hmac(
            'sha256',
            $token,
            (string) config('app.key'),
        ),
    ]);

    $payload = heartbeatPayload();

    $response = $this
        ->withHeaders(agentTokenHeaders($agent, $token))
        ->postJson('/api/agent/v1/heartbeat', $payload);

    $response
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'id',
                'agent_id',
                'status',
                'hostname',
                'runtime_network',
                'capabilities',
                'metadata',
                'last_seen_at',
            ],
        ])
        ->assertJsonPath('data.id', $agent->id)
        ->assertJsonPath('data.agent_id', $agent->agent_id)
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.hostname', 'runtime-01')
        ->assertJsonPath('data.runtime_network', 'sakala-runtime')
        ->assertJsonPath(
            'data.capabilities',
            [
                'docker-runtime',
                'project-inspection',
            ],
        );

    $agent->refresh();

    expect($agent->status)
        ->toBe(AgentNodeStatus::Ready)
        ->and($agent->hostname)
        ->toBe('runtime-01')
        ->and($agent->runtime_network)
        ->toBe('sakala-runtime')
        ->and($agent->capabilities)
        ->toBe([
            'docker-runtime',
            'project-inspection',
        ])
        ->and($agent->metadata)
        ->toBe($payload['metadata'])
        ->and($agent->last_seen_at)
        ->not->toBeNull();
});

test('heartbeat updates existing agent instead of creating another node', function (): void {
    $token = 'agent-secret-token';

    $agent = activeAgent($token);

    $response = $this
        ->withHeaders(agentTokenHeaders($agent, $token))
        ->postJson(
            '/api/agent/v1/heartbeat',
            heartbeatPayload(),
        );

    $response
        ->assertOk()
        ->assertJsonPath('data.agent_id', $agent->agent_id);

    expect(AgentNode::query()->count())
        ->toBe(1);

    $agent->refresh();

    expect($agent->agent_id)
        ->toBe($agent->agent_id);
});

test('agent can send heartbeat repeatedly without creating another node', function (): void {
    $token = 'agent-secret-token';

    $agent = AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Offline,
        'token_hash' => hash_hmac(
            'sha256',
            $token,
            (string) config('app.key'),
        ),
    ]);

    $payload = heartbeatPayload();

    $first = $this
        ->withHeaders(agentTokenHeaders($agent, $token))
        ->postJson('/api/agent/v1/heartbeat', $payload);

    $first->assertOk();

    $agent->refresh();

    $firstLastSeenAt = $agent->last_seen_at;

    expect($firstLastSeenAt)
        ->not->toBeNull();

    $second = $this
        ->withHeaders(agentTokenHeaders($agent, $token))
        ->postJson('/api/agent/v1/heartbeat', $payload);

    $second
        ->assertOk()
        ->assertJsonPath('data.agent_id', $agent->agent_id)
        ->assertJsonPath('data.status', 'ready');

    expect(AgentNode::query()->count())
        ->toBe(1);

    $agent->refresh();

    expect($agent->status)
        ->toBe(AgentNodeStatus::Ready);

    expect($agent->last_seen_at)
        ->not->toBeNull();

    expect($agent->last_seen_at->greaterThanOrEqualTo($firstLastSeenAt))
        ->toBeTrue();
});

test('heartbeat stores status reported by agent', function (): void {
    $token = 'agent-secret-token';

    $agent = activeAgent($token);

    $response = $this
        ->withHeaders(agentTokenHeaders($agent, $token))
        ->postJson(
            '/api/agent/v1/heartbeat',
            heartbeatPayload([
                'status' => 'degraded',
            ]),
        );

    $response
        ->assertOk()
        ->assertJsonPath('data.status', 'degraded');

    $agent->refresh();

    expect($agent->status)
        ->toBe(AgentNodeStatus::Degraded);
});

test('heartbeat updates agent runtime information', function (): void {
    $token = 'agent-secret-token';

    $agent = activeAgent($token);

    $payload = heartbeatPayload([
        'hostname' => 'runtime-02',
        'runtime_network' => 'custom-runtime',
        'capabilities' => [
            'docker-runtime',
            'project-inspection',
            'build-cache',
        ],
    ]);

    $response = $this
        ->withHeaders(agentTokenHeaders($agent, $token))
        ->postJson('/api/agent/v1/heartbeat', $payload);

    $response
        ->assertOk()
        ->assertJsonPath('data.hostname', 'runtime-02')
        ->assertJsonPath('data.runtime_network', 'custom-runtime');

    $agent->refresh();

    expect($agent->hostname)
        ->toBe('runtime-02')
        ->and($agent->runtime_network)
        ->toBe('custom-runtime')
        ->and($agent->capabilities)
        ->toBe([
            'docker-runtime',
            'project-inspection',
            'build-cache',
        ]);
});

test('heartbeat uses server time for last seen instead of sent at', function (): void {
    $token = 'agent-secret-token';

    $agent = activeAgent($token);

    $payload = heartbeatPayload([
        'sent_at' => '2020-01-01T00:00:00Z',
    ]);

    $before = now()->startOfSecond();

    $response = $this
        ->withHeaders(agentTokenHeaders($agent, $token))
        ->postJson('/api/agent/v1/heartbeat', $payload);

    $response->assertOk();

    $agent->refresh();

    expect($agent->last_seen_at)
        ->not->toBeNull();

    expect($agent->last_seen_at->greaterThanOrEqualTo($before))
        ->toBeTrue();

    expect($agent->last_seen_at->year)
        ->not->toBe(2020);
});

test('heartbeat rejects missing authorization header', function (): void {
    $agent = activeAgent();

    $response = $this
        ->withHeaders([
            'X-Agent-Id' => $agent->agent_id,
        ])
        ->postJson(
            '/api/agent/v1/heartbeat',
            heartbeatPayload(),
        );

    $response
        ->assertUnauthorized()
        ->assertJson([
            'message' => 'Unauthorized',
        ]);
});

test('heartbeat rejects missing agent identity header', function (): void {
    $token = 'agent-secret-token';

    activeAgent($token);

    $response = $this
        ->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])
        ->postJson(
            '/api/agent/v1/heartbeat',
            heartbeatPayload(),
        );

    $response
        ->assertUnauthorized()
        ->assertJson([
            'message' => 'Unauthorized',
        ]);
});

test('heartbeat rejects malformed authorization header', function (): void {
    $token = 'agent-secret-token';

    $agent = activeAgent($token);

    $response = $this
        ->withHeaders([
            'Authorization' => 'Basic '.$token,
            'X-Agent-Id' => $agent->agent_id,
        ])
        ->postJson(
            '/api/agent/v1/heartbeat',
            heartbeatPayload(),
        );

    $response
        ->assertUnauthorized()
        ->assertJson([
            'message' => 'Unauthorized',
        ]);
});

test('heartbeat rejects unknown agent identity', function (): void {
    $token = 'agent-secret-token';

    activeAgent($token);

    $response = $this
        ->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Agent-Id' => 'unknown-agent-id',
        ])
        ->postJson(
            '/api/agent/v1/heartbeat',
            heartbeatPayload(),
        );

    $response
        ->assertUnauthorized()
        ->assertJson([
            'message' => 'Unauthorized',
        ]);
});

test('heartbeat rejects invalid agent token', function (): void {
    $agent = activeAgent('correct-token');

    $response = $this
        ->withHeaders([
            'Authorization' => 'Bearer wrong-token',
            'X-Agent-Id' => $agent->agent_id,
        ])
        ->postJson(
            '/api/agent/v1/heartbeat',
            heartbeatPayload(),
        );

    $response
        ->assertUnauthorized()
        ->assertJson([
            'message' => 'Unauthorized',
        ]);
});

test('heartbeat rejects revoked agent', function (): void {
    $token = 'agent-secret-token';

    $agent = AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Revoked,
        'token_hash' => hash_hmac(
            'sha256',
            $token,
            (string) config('app.key'),
        ),
    ]);

    $response = $this
        ->withHeaders(agentTokenHeaders($agent, $token))
        ->postJson(
            '/api/agent/v1/heartbeat',
            heartbeatPayload(),
        );

    $response
        ->assertForbidden()
        ->assertJson([
            'message' => 'Forbidden',
        ]);
});

test('heartbeat rejects invalid payload', function (): void {
    $token = 'agent-secret-token';

    $agent = activeAgent($token);

    $response = $this
        ->withHeaders(agentTokenHeaders($agent, $token))
        ->postJson('/api/agent/v1/heartbeat', [
            'status' => 'invalid-status',
            'hostname' => '',
            'runtime_network' => '',
            'capabilities' => 'invalid',
            'metadata' => [],
            'sent_at' => 'not-a-date',
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'status',
            'hostname',
            'runtime_network',
            'capabilities',
            'metadata.version',
            'metadata.protocol_version',
            'metadata.runtime_driver',
            'metadata.lifecycle_state',
            'metadata.uptime_seconds',
            'metadata.resources',
            'metadata.workloads',
            'metadata.disk_pressure',
            'metadata.runtime_dependencies',
            'metadata.execution',
            'metadata.startup_reconciliation',
            'sent_at',
        ]);
});

test('heartbeat rejects invalid nested workload metadata', function (): void {
    $token = 'agent-secret-token';

    $agent = activeAgent($token);

    $payload = heartbeatPayload();

    unset($payload['metadata']['workloads']['active']);

    $response = $this
        ->withHeaders(agentTokenHeaders($agent, $token))
        ->postJson('/api/agent/v1/heartbeat', $payload);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'metadata.workloads.active',
        ]);
});

test('heartbeat rejects invalid nested startup reconciliation metadata', function (): void {
    $token = 'agent-secret-token';

    $agent = activeAgent($token);

    $payload = heartbeatPayload();

    unset(
        $payload['metadata']['startup_reconciliation']['orphans'],
    );

    $response = $this
        ->withHeaders(agentTokenHeaders($agent, $token))
        ->postJson('/api/agent/v1/heartbeat', $payload);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'metadata.startup_reconciliation.orphans',
        ]);
});

test('heartbeat rejects invalid capability items', function (): void {
    $token = 'agent-secret-token';

    $agent = activeAgent($token);

    $payload = heartbeatPayload([
        'capabilities' => [
            'docker-runtime',
            123,
        ],
    ]);

    $response = $this
        ->withHeaders(agentTokenHeaders($agent, $token))
        ->postJson('/api/agent/v1/heartbeat', $payload);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'capabilities.1',
        ]);
});
