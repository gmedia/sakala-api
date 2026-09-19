<?php

declare(strict_types=1);

use App\Actions\Agent\ClaimAgentCommandAction;
use App\Actions\Agent\CompleteAgentCommandAction;
use App\Actions\Agent\FailAgentCommandAction;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\Agent\CommandConflictException;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;

beforeEach(function (): void {
    concurrencyRequiresPostgres();

    $this->artisan('migrate:fresh');
});

function lockingAgent(array $capabilities = ['docker-runtime', 'dockerfile-build']): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'capabilities' => $capabilities,
    ]);
}

test('does not claim deploy command when suspend wins the project lock', function (): void {
    $agent = lockingAgent();
    $project = Project::factory()->create(['status' => ProjectStatus::Active]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'agent_node_id' => $agent->id,
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
    ]);

    // Transaction A is the admin suspend: it takes the project row lock that
    // ClaimAgentCommandAction re-validates under and flips the policy.
    whileTransactionHoldsLocks(
        holdLocks: function () use ($project): void {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail()
                ->update(['status' => ProjectStatus::Suspended]);
        },
        contend: fn () => app(ClaimAgentCommandAction::class)->handle(agent: $agent, commandId: $command->id),
    );

    // After the suspend commits, a fresh claim observes Suspended and conflicts.
    expect(fn () => app(ClaimAgentCommandAction::class)->handle(agent: $agent, commandId: $command->id))
        ->toThrow(CommandConflictException::class);

    expect($project->fresh()->status)->toBe(ProjectStatus::Suspended)
        ->and($command->fresh()->status)->toBe(AgentCommandStatus::Pending);
});

test('claim waits on deployment lock before project lock during deployment transition', function (): void {
    $agent = lockingAgent();
    $project = Project::factory()->create(['status' => ProjectStatus::Active]);
    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Building,
    ]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $agent->id,
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
    ]);

    // Transaction A is TransitionDeploymentAction: Deployment first, then
    // Project. A deadlock (40P01) instead of lock_not_available would mean
    // the claim takes those locks in the opposite order.
    whileTransactionHoldsLocks(
        holdLocks: function () use ($deployment, $project): void {
            Deployment::query()->whereKey($deployment->id)->lockForUpdate()->firstOrFail();
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
        },
        contend: fn () => app(ClaimAgentCommandAction::class)->handle(agent: $agent, commandId: $command->id),
    );

    app(ClaimAgentCommandAction::class)->handle(agent: $agent, commandId: $command->id);

    expect($command->fresh()->status)->toBe(AgentCommandStatus::Claimed)
        ->and($deployment->fresh()->agent_node_id)->toBe($agent->id);
});

test('complete and fail serialize on the same command lock', function (): void {
    $agent = lockingAgent();
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Running,
        'claimed_at' => now(),
        'agent_node_id' => $agent->id,
    ]);

    // Transaction A is CompleteAgentCommandAction holding the command row;
    // FailAgentCommandAction must wait on it rather than write concurrently.
    whileTransactionHoldsLocks(
        holdLocks: function () use ($command): void {
            AgentCommand::query()->whereKey($command->id)->lockForUpdate()->firstOrFail();
        },
        contend: fn () => app(FailAgentCommandAction::class)->handle(
            agent: $agent,
            commandId: $command->id,
            errorCode: 'runtime_execution_failed',
            errorMessage: 'container crashed',
        ),
    );

    app(CompleteAgentCommandAction::class)->handle(
        agent: $agent,
        commandId: $command->id,
        result: ['healthy' => true],
    );

    $command->refresh();

    expect($command->status)->toBe(AgentCommandStatus::Succeeded)
        ->and($command->failed_at)->toBeNull()
        ->and($command->error_code)->toBeNull();
});
