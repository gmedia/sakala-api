<?php

declare(strict_types=1);

use App\Actions\Agent\ClaimAgentCommandAction;
use App\Actions\Deployment\CancelDeploymentAction;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Exceptions\Agent\CommandConflictException;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;

beforeEach(function (): void {
    concurrencyRequiresPostgres();

    $this->artisan('migrate:fresh');
});

/** @return array{AgentNode, Deployment, AgentCommand} */
function lockingCancelContext(): array
{
    $node = AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'protocol_version' => 4,
        'desired_state' => AgentNodeDesiredState::Active,
        'capabilities' => ['docker-runtime', 'dockerfile-build', 'railpack-build'],
        'last_seen_at' => now(),
    ]);
    $project = Project::factory()->create();
    $deployment = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Queued,
        'sequence' => 1,
    ]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
        'expires_at' => null,
    ]);

    return [$node, $deployment, $command];
}

test('a claim cannot slip past a cancel that holds the deployment row', function (): void {
    [$node, $deployment, $command] = lockingCancelContext();

    whileTransactionHoldsLocks(
        holdLocks: function () use ($deployment, $command): void {
            // The Pending path of CancelDeploymentAction: deployment, then command.
            Deployment::query()->whereKey($deployment->id)->lockForUpdate()->firstOrFail();
            AgentCommand::query()->whereKey($command->id)->update(['status' => AgentCommandStatus::Cancelled]);
        },
        contend: fn () => app(ClaimAgentCommandAction::class)->handle(agent: $node, commandId: $command->id),
    );

    expect(fn () => app(ClaimAgentCommandAction::class)->handle(agent: $node, commandId: $command->id))
        ->toThrow(CommandConflictException::class);
});

test('a cancel after a claim becomes a request to the agent', function (): void {
    [$node, $deployment, $command] = lockingCancelContext();

    app(ClaimAgentCommandAction::class)->handle(agent: $node, commandId: $command->id);

    // The action read a stale Pending view: the guarded update loses and it
    // falls through to recording the intent.
    $result = app(CancelDeploymentAction::class)->handle($deployment);

    expect($result->status)->toBe(DeploymentStatus::Queued)
        ->and($result->cancel_requested_at)->not->toBeNull()
        ->and($command->fresh()->status)->toBe(AgentCommandStatus::Claimed)
        ->and($command->fresh()->cancel_requested_at)->not->toBeNull();
});
