<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Agent;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\UserRole;
use App\Models\AgentCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @uses \App\Http\Controllers\Api\V1\Agent\AgentController */
final class AgentFullLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_full_lifecycle_register_to_complete(): void
    {
        // 1. Register agent and capture plaintext token
        $response = $this->postJson('/api/agent/v1/agents', [
            'name' => 'Lifecycle Agent',
            'description' => 'End-to-end lifecycle test',
        ]);

        $response->assertCreated();
        $token = $response->json('token');
        $agentId = (string) $response->json('data.agent_id');

        $this->assertIsString($token);
        $this->assertEquals(64, strlen($token));

        // Token must not appear in show response (admin still authenticated from setUp)
        $showResponse = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/agent/v1/agents/{$response->json('data.id')}");
        $showResponse->assertOk();
        $this->assertArrayNotHasKey('token', $showResponse->json('data'));

        // Clear Sanctum auth — all requests below must rely solely on Agent Bearer + X-Agent-Id
        $this->actingAsGuest('sanctum');

        // 2. Send heartbeat with the registered token
        $heartbeatResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Agent-Id' => $agentId,
        ])->postJson('/api/agent/v1/heartbeat', heartbeatPayload());

        $heartbeatResponse->assertOk();

        // 3. Poll commands — no commands yet
        $pollResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Agent-Id' => $agentId,
        ])->getJson('/api/agent/v1/commands');

        $pollResponse->assertOk();
        $pollResponse->assertJsonCount(0, 'data');

        // 4. Create a pending command via factory
        $command = AgentCommand::factory()->create([
            'type' => AgentCommandType::HealthCheck,
            'status' => AgentCommandStatus::Pending,
            'available_at' => now()->subMinute(),
            'expires_at' => now()->addMinutes(10),
            'agent_node_id' => null,
        ]);

        // 5. Poll again — should see the command
        $pollResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Agent-Id' => $agentId,
        ])->getJson('/api/agent/v1/commands');

        $pollResponse->assertOk();
        $pollResponse->assertJsonCount(1, 'data');
        $this->assertEquals($command->id, $pollResponse->json('data.0.id'));

        // Token must NOT leak in poll response
        $pollJson = json_encode($pollResponse->json());
        $this->assertStringNotContainsString($token, $pollJson);

        // 6. Claim the command
        $claimResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Agent-Id' => $agentId,
        ])->postJson("/api/agent/v1/commands/{$command->id}/claim");

        $claimResponse->assertOk();
        $this->assertEquals('Claimed', $claimResponse->json('data.status'));

        // 7. Complete the command
        $completeResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Agent-Id' => $agentId,
        ])->postJson("/api/agent/v1/commands/{$command->id}/complete");

        $completeResponse->assertNoContent();

        // 8. Verify command is Succeeded in DB
        $command->refresh();
        $this->assertEquals(AgentCommandStatus::Succeeded, $command->status);

        // 9. Poll again — terminal command should no longer appear
        $pollResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Agent-Id' => $agentId,
        ])->getJson('/api/agent/v1/commands');

        $pollResponse->assertOk();
        $pollResponse->assertJsonCount(0, 'data');

        // 10. Idempotent complete should return 204
        $completeResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Agent-Id' => $agentId,
        ])->postJson("/api/agent/v1/commands/{$command->id}/complete");

        $completeResponse->assertNoContent();
    }
}
