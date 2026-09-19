<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentNodeDesiredState;
use App\Models\AgentNode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function nodeStateAgent(string $token = 'node-state-token', array $overrides = []): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
        ...$overrides,
    ]);
}

function nodeStateHeaders(AgentNode $agent, string $token = 'node-state-token'): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'X-Agent-Id' => $agent->agent_id,
    ];
}

test('agent restores its desired lifecycle state before polling', function (): void {
    $agent = nodeStateAgent();

    $this->withHeaders(nodeStateHeaders($agent))
        ->getJson('/api/agent/v1/node-state')
        ->assertOk()
        ->assertExactJson(['data' => ['desired_state' => 'active']]);
});

test('node-state reflects the control-plane intent, not the reported status', function (): void {
    $agent = nodeStateAgent(overrides: ['desired_state' => AgentNodeDesiredState::Draining]);

    $this->withHeaders(nodeStateHeaders($agent))
        ->getJson('/api/agent/v1/node-state')
        ->assertOk()
        ->assertJsonPath('data.desired_state', 'draining');
});

test('node-state requires agent authentication', function (): void {
    nodeStateAgent();

    $this->getJson('/api/agent/v1/node-state')->assertUnauthorized();
});

test('node-state rejects a bearer token presented with another node identity', function (): void {
    nodeStateAgent('owner-token');
    $other = nodeStateAgent('other-token');

    $this->withHeaders([
        'Authorization' => 'Bearer owner-token',
        'X-Agent-Id' => $other->agent_id,
    ])->getJson('/api/agent/v1/node-state')->assertUnauthorized();
});

test('node-state is forbidden for revoked agents so bootstrap fails closed', function (): void {
    $agent = nodeStateAgent(overrides: ['auth_status' => AgentAuthStatus::Revoked]);

    $this->withHeaders(nodeStateHeaders($agent))
        ->getJson('/api/agent/v1/node-state')
        ->assertForbidden();
});
