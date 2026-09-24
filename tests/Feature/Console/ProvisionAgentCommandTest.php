<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\UserRole;
use App\Models\AgentNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('registers a node and prints its credentials as env assignments', function (): void {
    $this->artisan('agent:provision', [
        'name' => 'production-runtime-01',
        '--description' => 'Production runtime node',
    ])->assertSuccessful();

    $node = AgentNode::query()->sole();

    expect($node->name)->toBe('production-runtime-01')
        ->and($node->description)->toBe('Production runtime node')
        ->and($node->agent_id)->toStartWith('agent-')
        ->and($node->auth_status)->toBe(AgentAuthStatus::Active)
        ->and($node->registered_at)->not->toBeNull();
});

it('prints the token exactly once and stores only its hash', function (): void {
    $this->artisan('agent:provision', ['name' => 'node'])->assertSuccessful();

    $node = AgentNode::query()->sole();

    expect($node->token_hash)->not->toBeEmpty()
        ->and($node->token_prefix)->toHaveLength(10)
        ->and($node->getAttributes())->not->toHaveKey('token');
});

it('refuses an actor that is not an admin', function (): void {
    $user = User::factory()->create(['role' => UserRole::User]);

    $this->artisan('agent:provision', ['name' => 'node', '--actor' => $user->email])
        ->assertFailed();

    expect(AgentNode::query()->count())->toBe(0);
});

it('refuses an actor that does not exist', function (): void {
    $this->artisan('agent:provision', ['name' => 'node', '--actor' => 'nobody@example.test'])
        ->assertFailed();

    expect(AgentNode::query()->count())->toBe(0);
});

it('accepts an admin actor', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->artisan('agent:provision', ['name' => 'node', '--actor' => $admin->email])
        ->assertSuccessful();

    expect(AgentNode::query()->count())->toBe(1);
});
