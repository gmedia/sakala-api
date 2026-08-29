<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentNodeStatus;
use App\Models\AgentNode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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

test('heartbeat accepts valid agent lifecycle statuses', function (string $status): void {
    $token = 'agent-secret-token';

    $agent = activeAgent($token);

    $response = $this
        ->withHeaders(agentTokenHeaders($agent, $token))
        ->postJson(
            '/api/agent/v1/heartbeat',
            heartbeatPayload([
                'status' => $status,
            ]),
        );

    $response
        ->assertOk()
        ->assertJsonPath('data.status', $status);

    $agent->refresh();

    expect($agent->status->value)->toBe($status);
})->with([
    'ready',
    'busy',
    'degraded',
    'draining',
    'drained',
    'maintenance',
]);

test('heartbeat rejects offline status because offline is controlled by control plane', function (): void {
    $token = 'agent-secret-token';

    $agent = activeAgent($token);

    $response = $this
        ->withHeaders(agentTokenHeaders($agent, $token))
        ->postJson(
            '/api/agent/v1/heartbeat',
            heartbeatPayload([
                'status' => 'offline',
            ]),
        );

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('agent node can still have offline status', function (): void {
    $agent = AgentNode::factory()->create([
        'status' => AgentNodeStatus::Offline,
    ]);

    expect($agent->status)
        ->toBe(AgentNodeStatus::Offline);
});

test('heartbeat accepts degraded status with unavailable telemetry', function (): void {
    $token = 'agent-secret-token';

    $agent = activeAgent($token);

    $payload = heartbeatPayload([
        'status' => 'degraded',
        'metadata' => [
            'resources' => [
                'cpu_total' => null,
                'cpu_load_1m' => null,
                'memory_total_bytes' => null,
                'memory_available_bytes' => null,
                'disk_total_bytes' => null,
                'disk_available_bytes' => null,
                'workspace_used_bytes' => null,
            ],
            'workloads' => [
                'active' => null,
                'starting' => null,
                'unhealthy' => null,
                'stopped' => null,
            ],
            'execution' => [
                'active_commands' => null,
                'queued_local_commands' => null,
                'capacity_waiting_commands' => null,
                'active_builds' => null,
                'maximum_concurrent_builds' => null,
            ],
        ],
    ]);

    $response = $this
        ->withHeaders(agentTokenHeaders($agent, $token))
        ->postJson('/api/agent/v1/heartbeat', $payload);

    $response->assertOk();

    expect($agent->refresh()->status)
        ->toBe(AgentNodeStatus::Degraded);
});

test('heartbeat rejects detail collections exceeding maximum size', function (
    string $path,
): void {
    $token = 'agent-secret-token';

    $agent = activeAgent($token);

    $payload = heartbeatPayload();

    data_set(
        $payload,
        $path,
        array_fill(0, 51, ['value' => 'too-many']),
    );

    $response = $this
        ->withHeaders(agentTokenHeaders($agent, $token))
        ->postJson('/api/agent/v1/heartbeat', $payload);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$path]);
})->with([
    'unhealthy details' => 'metadata.workloads.unhealthy_details',
    'recovered workloads' => 'metadata.startup_reconciliation.recovered_workloads',
    'orphans' => 'metadata.startup_reconciliation.orphans',
    'stale routes' => 'metadata.startup_reconciliation.stale_routes',
    'stale images' => 'metadata.startup_reconciliation.stale_images',
    'compatibility issues' => 'metadata.startup_reconciliation.compatibility_issues',
]);

test('heartbeat rejects more than 50 capabilities', function (): void {
    $token = 'agent-secret-token';

    $agent = activeAgent($token);

    $payload = heartbeatPayload([
        'capabilities' => array_fill(0, 51, 'capability'),
    ]);

    $response = $this
        ->withHeaders(agentTokenHeaders($agent, $token))
        ->postJson('/api/agent/v1/heartbeat', $payload);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'capabilities',
        ]);
});

test('heartbeat rejects more than 50 compatibility issues', function (): void {
    $token = 'agent-secret-token';

    $agent = activeAgent($token);

    $payload = heartbeatPayload([
        'metadata' => [
            'startup_reconciliation' => [
                'compatibility_issues' => array_fill(0, 51, [
                    'component' => 'docker',
                    'message' => 'incompatible',
                ]),
            ],
        ],
    ]);

    $response = $this
        ->withHeaders(agentTokenHeaders($agent, $token))
        ->postJson('/api/agent/v1/heartbeat', $payload);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'metadata.startup_reconciliation.compatibility_issues',
        ]);
});

test('heartbeat rejects payload larger than 256 KiB', function (): void {
    $token = 'agent-secret-token';

    $agent = activeAgent($token);

    $payload = heartbeatPayload([
        'metadata' => [
            'large_field' => str_repeat('x', 300 * 1024),
        ],
    ]);

    $response = $this
        ->withHeaders(agentTokenHeaders($agent, $token))
        ->postJson('/api/agent/v1/heartbeat', $payload);

    $response
        ->assertStatus(413);
});
