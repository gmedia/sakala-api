<?php

declare(strict_types=1);

use App\Actions\Agent\ClaimAgentCommandAction;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectInspectionStatus;
use App\Exceptions\Agent\CommandConflictException;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\Deployment\DeploymentInspectionHold;

beforeEach(function (): void {
    concurrencyRequiresPostgres();

    $this->artisan('migrate:fresh');
});

/** @return array{AgentNode, Project, Deployment, AgentCommand} A deployment held for an inspection that just finished with port 8080. */
function lockingHoldContext(): array
{
    $node = AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'protocol_version' => 4,
        'desired_state' => AgentNodeDesiredState::Active,
        'capabilities' => ['docker-runtime', 'dockerfile-build', 'railpack-build'],
        'last_seen_at' => now(),
    ]);
    $project = Project::factory()->create([
        'inspection_status' => ProjectInspectionStatus::Succeeded,
        'detected_port' => 8080,
    ]);
    $deployment = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Queued,
        'sequence' => 1,
        'container_port' => Project::DEFAULT_CONTAINER_PORT,
        'awaiting_inspection_until' => now()->addMinutes(15),
    ]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Pending,
        'payload' => ['container_port' => Project::DEFAULT_CONTAINER_PORT],
        'available_at' => now()->subMinute(),
        'expires_at' => null,
    ]);

    return [$node, $project, $deployment, $command];
}

test('a claim waits for a release that holds the deployment row, then runs on the released port', function (): void {
    [$node, $project, $deployment, $command] = lockingHoldContext();

    $blocked = whileTransactionHoldsLocks(
        holdLocks: fn () => app(DeploymentInspectionHold::class)->settleProject($project->id),
        contend: fn () => app(ClaimAgentCommandAction::class)->handle($node, $command->id),
    );

    // Claim locks the node, then the deployment; the release holds the latter.
    expect($blocked->getMessage())->toContain('"deployments"');

    $claimed = app(ClaimAgentCommandAction::class)->handle($node, $command->id);

    expect($claimed->status)->toBe(AgentCommandStatus::Claimed)
        ->and($claimed->payload['container_port'])->toBe(8080)
        ->and($deployment->fresh()?->container_port)->toBe(8080);
});

test('a claim that read the deployment before the hold was set still cannot run it', function (): void {
    [$node, , $deployment, $command] = lockingHoldContext();

    // The hold is on the row the claim locks, so the claim sees it whatever
    // its poll saw.
    expect(fn () => app(ClaimAgentCommandAction::class)->handle($node, $command->id))
        ->toThrow(CommandConflictException::class);

    expect($command->fresh()?->status)->toBe(AgentCommandStatus::Pending)
        ->and($deployment->fresh()?->awaiting_inspection_until)->not->toBeNull();
});
