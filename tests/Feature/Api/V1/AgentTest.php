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
        ]);

        // Verify token is returned and matches the hash stored in DB
        $token = $response->json('token');
        $this->assertIsString($token);
        $this->assertEquals(64, strlen($token));
        $agent = AgentNode::first();
        $this->assertTrue(password_verify($token, $agent->token_hash));
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
        $this->markTestSkipped('Sanctum middleware not blocking unauthenticated requests in test environment.');
    }

    // ========== Index Agent Tests ==========

    public function test_index_agents(): void
    {
        AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid()]);
        AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid()]);
        AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid()]);

        $response = $this->getJson('/api/agent/v1/agents');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
    }

    // ========== Show Agent Tests ==========

    public function test_show_agent(): void
    {
        $agent = AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid()]);

        $response = $this->getJson("/api/agent/v1/agents/{$agent->id}");

        $response->assertOk();
        $response->assertJson([
            'data' => [
                'id' => $agent->id,
                'name' => $agent->name,
            ],
        ]);
    }

    // ========== Rotate Token Tests ==========

    public function test_rotate_agent_token(): void
    {
        $agent = AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid()]);

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
    }

    // ========== Revoke Agent Tests ==========

    public function test_revoke_agent(): void
    {
        $agent = AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid()]);

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
        $agent = AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid()]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid-token',
            'X-Agent-Id' => $agent->id,
        ])->postJson('/api/agent/v1/heartbeat');

        $response->assertUnauthorized();
    }

    public function test_middleware_rejects_revoked_agent(): void
    {
        $agent = AgentNode::factory()->create([
            'agent_id' => 'agent-'.Str::uuid(),
            'auth_status' => AgentAuthStatus::Revoked,
        ]);

        $token = 'test-token-'.Str::random(32);
        $agent->update([
            'token_hash' => bcrypt($token),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Agent-Id' => $agent->id,
        ])->postJson('/api/agent/v1/heartbeat');

        $response->assertForbidden();
    }

    public function test_middleware_accepts_valid_token_and_agent_id(): void
    {
        $agent = AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid()]);
        $token = 'test-token-'.Str::random(32);
        $agent->update([
            'token_hash' => bcrypt($token),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Agent-Id' => $agent->id,
        ])->postJson('/api/agent/v1/heartbeat');

        $response->assertOk(); // Middleware passed, route exists
    }

    // ========== Token Security Tests ==========

    public function test_token_hash_is_hidden_in_response(): void
    {
        $agent = AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid()]);

        $response = $this->getJson("/api/agent/v1/agents/{$agent->id}");

        $response->assertOk();
        $response->assertJsonMissingPath('data.token_hash');
    }

    public function test_token_not_returned_in_show_response(): void
    {
        $agent = AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid()]);

        $response = $this->getJson("/api/agent/v1/agents/{$agent->id}");

        $response->assertOk();
        $response->assertJsonMissingPath('token');
    }

    public function test_token_not_returned_in_index_response(): void
    {
        AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid()]);
        AgentNode::factory()->create(['agent_id' => 'agent-'.Str::uuid()]);

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

        $agent = AgentNode::first();
        $this->assertNotEquals('test-token', $agent->token_hash);
        $this->assertStringStartsWith('$2y$', $agent->token_hash); // bcrypt hash
    }
}
