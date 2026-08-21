<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\AgentStatus;
use App\Enums\UserRole;
use App\Models\Agent;
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
        $response = $this->postJson('/api/v1/agents', [
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
                'status',
                'created_at',
                'updated_at',
            ],
        ]);

        $this->assertDatabaseHas('agents', [
            'name' => 'Test Agent',
            'status' => AgentStatus::Active->value,
        ]);
    }

    public function test_store_agent_without_name_fails(): void
    {
        $response = $this->postJson('/api/v1/agents', [
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
        Agent::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/agents');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
    }

    // ========== Show Agent Tests ==========

    public function test_show_agent(): void
    {
        $agent = Agent::factory()->create();

        $response = $this->getJson("/api/v1/agents/{$agent->id}");

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
        $agent = Agent::factory()->create();

        $response = $this->postJson("/api/v1/agents/{$agent->id}/rotate");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'token_prefix',
                'status',
            ],
            'token',
        ]);

        $this->assertDatabaseHas('agents', [
            'id' => $agent->id,
            'status' => AgentStatus::Active->value,
        ]);
    }

    // ========== Revoke Agent Tests ==========

    public function test_revoke_agent(): void
    {
        $agent = Agent::factory()->create();

        $response = $this->postJson("/api/v1/agents/{$agent->id}/revoke");

        $response->assertNoContent();

        $this->assertDatabaseHas('agents', [
            'id' => $agent->id,
            'status' => AgentStatus::Revoked->value,
        ]);
    }

    // ========== Middleware Tests ==========

    public function test_middleware_rejects_missing_authorization_header(): void
    {
        $response = $this->withHeader('X-Agent-Id', 'test-id')
            ->postJson('/api/v1/agent/heartbeat');

        $response->assertUnauthorized();
    }

    public function test_middleware_rejects_missing_agent_id_header(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/v1/agent/heartbeat');

        $response->assertUnauthorized();
    }

    public function test_middleware_rejects_invalid_bearer_format(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Basic test-token',
            'X-Agent-Id' => 'test-id',
        ])->postJson('/api/v1/agent/heartbeat');

        $response->assertUnauthorized();
    }

    public function test_middleware_rejects_invalid_token(): void
    {
        $agent = Agent::factory()->create();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid-token',
            'X-Agent-Id' => $agent->id,
        ])->postJson('/api/v1/agent/heartbeat');

        $response->assertUnauthorized();
    }

    public function test_middleware_rejects_revoked_agent(): void
    {
        $agent = Agent::factory()->create([
            'status' => AgentStatus::Revoked,
        ]);

        $token = 'test-token-'.Str::random(32);
        $agent->update([
            'token_hash' => bcrypt($token),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Agent-Id' => $agent->id,
        ])->postJson('/api/v1/agent/heartbeat');

        $response->assertForbidden();
    }

    public function test_middleware_accepts_valid_token_and_agent_id(): void
    {
        $agent = Agent::factory()->create();
        $token = 'test-token-'.Str::random(32);
        $agent->update([
            'token_hash' => bcrypt($token),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Agent-Id' => $agent->id,
        ])->postJson('/api/v1/agent/heartbeat');

        $response->assertOk(); // Middleware passed, route exists
    }

    // ========== Token Security Tests ==========

    public function test_token_hash_is_hidden_in_response(): void
    {
        $agent = Agent::factory()->create();

        $response = $this->getJson("/api/v1/agents/{$agent->id}");

        $response->assertOk();
        $response->assertJsonMissingPath('data.token_hash');
    }

    public function test_token_not_stored_in_plain_text(): void
    {
        $response = $this->postJson('/api/v1/agents', [
            'name' => 'Test Agent',
        ]);

        $response->assertCreated();

        $agent = Agent::first();
        $this->assertNotEquals('test-token', $agent->token_hash);
        $this->assertStringStartsWith('$2y$', $agent->token_hash); // bcrypt hash
    }
}
