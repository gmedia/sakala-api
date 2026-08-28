<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\AgentAuthStatus;
use App\Enums\UserRole;
use App\Models\AgentNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @uses \App\Http\Controllers\Api\V1\Agent\AgentController */
final class AgentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($this->admin, 'sanctum');
    }

    public function heartbeatPayload(array $overrides = []): array
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

    // ========== Store Agent Tests ==========

    public function test_store_agent_with_valid_data(): void
    {
        $response = $this->postJson('/api/agent/v1/agents', [
            'name' => 'Test Agent',
            'description' => 'Test Description',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'agent_id',
                'name',
                'description',
                'token_prefix',
                'auth_status',
                'status',
                'created_at',
                'updated_at',
            ],
            'token',
        ]);

        $this->assertDatabaseHas('agent_nodes', [
            'name' => 'Test Agent',
            'auth_status' => AgentAuthStatus::Active->value,
            'status' => 'offline',
        ]);

        // Verify token is returned and matches the hash stored in DB
        $token = $response->json('token');
        $this->assertIsString($token);
        $this->assertEquals(64, strlen($token));
        $agent = AgentNode::first();
        $this->assertSame(
            hash_hmac('sha256', $token, (string) config('app.key')),
            $agent->token_hash,
        );
        $this->assertStringStartsWith($agent->token_prefix, $token);
    }

    public function test_store_agent_without_name_fails(): void
    {
        $response = $this->postJson('/api/agent/v1/agents', [
            'description' => 'Test Description',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_store_agent_without_auth_fails(): void
    {
        $this->actingAsGuest();

        $response = $this->postJson('/api/agent/v1/agents', [
            'name' => 'Test Agent',
        ]);

        $response->assertUnauthorized();
    }

    public function test_store_agent_without_admin_role_fails(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/agent/v1/agents', [
            'name' => 'Test Agent',
        ]);

        $response->assertForbidden();
    }

    // ========== Index Agent Tests ==========

    public function test_index_agents(): void
    {
        AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid7()]);
        AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid7()]);
        AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid7()]);

        $response = $this->getJson('/api/agent/v1/agents');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
    }

    // ========== Show Agent Tests ==========

    public function test_show_agent(): void
    {
        $agent = AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid7()]);

        $response = $this->getJson("/api/agent/v1/agents/{$agent->id}");

        $response->assertOk();
        $response->assertJson([
            'data' => [
                'id' => $agent->id,
                'name' => $agent->name,
            ],
        ]);
    }

    // ========== Token Tests ==========

    public function test_rotate_agent_token(): void
    {
        $agent = AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid7()]);

        $statusBeforeRotate = $agent->status;

        $response = $this->postJson("/api/agent/v1/agents/{$agent->id}/rotate");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'token_prefix',
                'auth_status',
                'status',
            ],
            'token',
        ]);

        $this->assertDatabaseHas('agent_nodes', [
            'id' => $agent->id,
            'auth_status' => AgentAuthStatus::Active->value,
        ]);

        // Token rotation must not change runtime status
        $agent->refresh();
        $this->assertEquals($statusBeforeRotate, $agent->status);
    }

    public function test_rotate_agent_token_rejects_old_token(): void
    {
        $token = 'rotate-test-'.Str::random(32);
        $agent = AgentNode::factory()->create([
            'agent_id' => 'agent-'.Str::uuid7(),
            'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
        ]);

        // Old token works before rotation
        $responseBefore = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Agent-Id' => $agent->agent_id,
        ])->postJson('/api/agent/v1/heartbeat', heartbeatPayload());
        $responseBefore->assertOk();

        // Rotate the token
        $rotateResponse = $this->postJson("/api/agent/v1/agents/{$agent->id}/rotate");
        $rotateResponse->assertOk();
        $newToken = $rotateResponse->json('token');

        // Old token is now rejected
        $responseAfterOld = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Agent-Id' => $agent->agent_id,
        ])->postJson('/api/agent/v1/heartbeat');
        $responseAfterOld->assertUnauthorized();

        // New token is accepted
        $responseAfterNew = $this->withHeaders([
            'Authorization' => "Bearer {$newToken}",
            'X-Agent-Id' => $agent->agent_id,
        ])->postJson('/api/agent/v1/heartbeat', heartbeatPayload());
        $responseAfterNew->assertOk();
    }

    // ========== Rotate/Revoke Agent Tests ==========

    public function test_rotate_agent_token_does_not_reactivate_revoked(): void
    {
        $agent = AgentNode::factory()->create([
            'agent_id' => 'agent-'.Str::uuid7(),
            'auth_status' => AgentAuthStatus::Revoked,
        ]);

        $token = 'rotate-test-'.Str::random(32);
        $agent->update([
            'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
        ]);

        // Revoked agent with valid token returns 403 before rotation
        $responseBefore = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Agent-Id' => $agent->agent_id,
        ])->postJson('/api/agent/v1/heartbeat');
        $responseBefore->assertForbidden();

        // Rotate the token
        $rotateResponse = $this->postJson("/api/agent/v1/agents/{$agent->id}/rotate");
        $rotateResponse->assertOk();
        $newToken = $rotateResponse->json('token');

        // auth_status must stay Revoked after rotation
        $agent->refresh();
        $this->assertEquals(AgentAuthStatus::Revoked, $agent->auth_status);

        // New token rejected with 403 — proves rotation did not reactivate the agent
        $responseAfterNew = $this->withHeaders([
            'Authorization' => "Bearer {$newToken}",
            'X-Agent-Id' => $agent->agent_id,
        ])->postJson('/api/agent/v1/heartbeat');
        $responseAfterNew->assertForbidden();
    }

    // ========== Revoke Agent Tests ==========

    public function test_revoke_agent(): void
    {
        $agent = AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid7()]);

        $response = $this->postJson("/api/agent/v1/agents/{$agent->id}/revoke");

        $response->assertNoContent();

        $this->assertDatabaseHas('agent_nodes', [
            'id' => $agent->id,
            'auth_status' => AgentAuthStatus::Revoked->value,
        ]);
    }

    // ========== Middleware Tests ==========

    public function test_middleware_rejects_missing_authorization_header(): void
    {
        $response = $this->withHeader('X-Agent-Id', 'test-id')
            ->postJson('/api/agent/v1/heartbeat');

        $response->assertUnauthorized();
    }

    public function test_middleware_rejects_missing_agent_id_header(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/agent/v1/heartbeat');

        $response->assertUnauthorized();
    }

    public function test_middleware_rejects_invalid_bearer_format(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Basic test-token',
            'X-Agent-Id' => 'test-id',
        ])->postJson('/api/agent/v1/heartbeat');

        $response->assertUnauthorized();
    }

    public function test_middleware_rejects_invalid_token(): void
    {
        $agent = AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid7()]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid-token',
            'X-Agent-Id' => $agent->agent_id,
        ])->postJson('/api/agent/v1/heartbeat');

        $response->assertUnauthorized();
    }

    public function test_middleware_rejects_revoked_agent(): void
    {
        $agent = AgentNode::factory()->create([
            'agent_id' => 'agent-'.Str::uuid7(),
            'auth_status' => AgentAuthStatus::Revoked,
        ]);

        $token = 'test-token-'.Str::random(32);
        $agent->update([
            'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Agent-Id' => $agent->agent_id,
        ])->postJson('/api/agent/v1/heartbeat');

        $response->assertForbidden();
    }

    public function test_middleware_accepts_valid_token_and_agent_id(): void
    {
        $agent = AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid7()]);
        $token = 'test-token-'.Str::random(32);
        $agent->update([
            'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Agent-Id' => $agent->agent_id,
        ])->postJson('/api/agent/v1/heartbeat', heartbeatPayload());

        $response->assertOk(); // Middleware passed, route exists
    }

    // ========== Token Security Tests ==========

    public function test_token_hash_is_hidden_in_response(): void
    {
        $agent = AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid7()]);

        $response = $this->getJson("/api/agent/v1/agents/{$agent->id}");

        $response->assertOk();
        $response->assertJsonMissingPath('data.token_hash');
    }

    public function test_token_not_returned_in_show_response(): void
    {
        $agent = AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid7()]);

        $response = $this->getJson("/api/agent/v1/agents/{$agent->id}");

        $response->assertOk();
        $response->assertJsonMissingPath('token');
    }

    public function test_token_not_returned_in_index_response(): void
    {
        AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid7()]);
        AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid7()]);

        $response = $this->getJson('/api/agent/v1/agents');

        $response->assertOk();
        $response->assertJsonMissingPath('data.0.token');
    }

    public function test_token_not_stored_in_plain_text(): void
    {
        $response = $this->postJson('/api/agent/v1/agents', [
            'name' => 'Test Agent',
        ]);

        $response->assertCreated();

        $token = $response->json('token');
        $agent = AgentNode::first();
        $this->assertNotEquals($token, $agent->token_hash);
        $this->assertEquals(
            hash_hmac('sha256', $token, (string) config('app.key')),
            $agent->token_hash,
        );
    }

    public function test_middleware_rejects_mismatched_identity(): void
    {
        $agentA = AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid7()]);
        $agentB = AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid7()]);

        $token = 'test-token-'.Str::random(32);
        $agentA->update([
            'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Agent-Id' => $agentB->agent_id,
        ])->postJson('/api/agent/v1/heartbeat');

        $response->assertUnauthorized();
    }
}
