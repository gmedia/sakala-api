<?php

declare(strict_types=1);

use App\Actions\Agent\ClaimAgentCommandAction;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Exceptions\Agent\CommandConflictException;
use App\Models\AgentCommand;
use App\Models\AgentNode;

beforeEach(function (): void {
    concurrencyRequiresPostgres();

    $this->artisan('migrate:fresh');
});

test('a workload claim cannot slip past a drain that holds the node row', function (): void {
    $node = AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'protocol_version' => 4,
        'desired_state' => AgentNodeDesiredState::Active,
        'capabilities' => ['docker-runtime'],
        'last_seen_at' => now(),
    ]);
    $workload = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
        'agent_node_id' => $node->id,
        'available_at' => now()->subMinute(),
    ]);

    // Transaction A is the admin drain: it takes the node row lock that
    // ChangeAgentNodeLifecycleAction uses and moves the intent to draining.
    // The claim must wait on that row instead of reading the stale intent.
    whileTransactionHoldsLocks(
        holdLocks: function () use ($node): void {
            AgentNode::query()->whereKey($node->id)->lockForUpdate()->firstOrFail()
                ->update(['desired_state' => AgentNodeDesiredState::Draining]);
        },
        contend: fn () => app(ClaimAgentCommandAction::class)->handle(agent: $node, commandId: $workload->id),
    );

    // Once the drain has committed, the claim observes draining and conflicts;
    // the workload stays Pending for whoever the node hands off to later.
    expect(fn () => app(ClaimAgentCommandAction::class)->handle(agent: $node, commandId: $workload->id))
        ->toThrow(CommandConflictException::class);

    expect($workload->fresh()->status)->toBe(AgentCommandStatus::Pending)
        ->and($workload->fresh()->claimed_at)->toBeNull();
});
