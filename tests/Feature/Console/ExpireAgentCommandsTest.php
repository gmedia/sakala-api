<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentFailureCategory;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectInspectionStatus;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function expiryNode(string $token = 'expiry-token'): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'protocol_version' => 4,
        'desired_state' => AgentNodeDesiredState::Active,
        'capabilities' => ['docker-runtime', 'project-inspection', 'dockerfile-build', 'railpack-build'],
        'last_seen_at' => now(),
        'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
    ]);
}

function expiryHeaders(AgentNode $node, string $token = 'expiry-token'): array
{
    return ['Authorization' => 'Bearer '.$token, 'X-Agent-Id' => $node->agent_id];
}

test('claiming a command grants a lease from its execution timeout plus grace', function (): void {
    config(['sakala.agent.lease_grace_seconds' => 60]);
    $node = expiryNode();
    $project = Project::factory()->create();
    $deployment = Deployment::factory()->for($project)->create(['sequence' => 1, 'agent_node_id' => $node->id]);
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Pending,
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'payload' => ['timeouts' => ['command_timeout_seconds' => 300]],
    ]);
    $fallback = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
        'agent_node_id' => $node->id,
    ]);

    $this->freezeTime();
    $this->withHeaders(expiryHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();
    $this->withHeaders(expiryHeaders($node))->postJson("/api/agent/v1/commands/{$fallback->id}/claim", [])->assertOk();

    // Stored timestamps drop microseconds; compare at second precision.
    expect($command->fresh()->lease_expires_at?->timestamp)->toBe(now()->addSeconds(360)->timestamp)
        ->and($fallback->fresh()->lease_expires_at?->timestamp)->toBe(now()->addSeconds(
            (int) config('sakala.pilot_limits.timeouts.command_timeout_seconds') + 60,
        )->timestamp);
});

test('a claimed deployment whose lease ran out is expired and the deployment fails as a timeout', function (): void {
    $node = expiryNode();
    $project = Project::factory()->create(['runtime_status' => RuntimeStatus::Deploying]);
    $deployment = Deployment::factory()->for($project)->create([
        'sequence' => 1, 'status' => DeploymentStatus::Building, 'agent_node_id' => $node->id,
    ]);
    $expired = AgentCommand::factory()->create([
        'type' => AgentCommandType::DeployProject, 'status' => AgentCommandStatus::Running,
        'project_id' => $project->id, 'deployment_id' => $deployment->id, 'agent_node_id' => $node->id,
        'payload' => [], 'lease_expires_at' => now()->subMinute(),
    ]);
    $healthy = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck, 'status' => AgentCommandStatus::Claimed,
        'agent_node_id' => $node->id, 'lease_expires_at' => now()->addMinutes(10),
    ]);

    $this->artisan('agent:expire-commands')
        ->expectsOutputToContain('Expired 0 unclaimed and 1 leased agent command(s).')
        ->assertSuccessful();

    $expired->refresh();
    expect($expired->status)->toBe(AgentCommandStatus::Expired)
        ->and($expired->error_code)->toBe('command_lease_expired')
        ->and($expired->failed_at)->not->toBeNull()
        ->and($healthy->fresh()->status)->toBe(AgentCommandStatus::Claimed);

    $deployment->refresh();
    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->failure_code)->toBe('command_lease_expired')
        ->and($deployment->finished_at)->not->toBeNull()
        ->and($project->fresh()->runtime_status)->toBe(RuntimeStatus::Failed);

    $this->actingAs($project->user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}")
        ->assertOk()
        ->assertJsonPath('data.failure.category', DeploymentFailureCategory::Timeout->value);

    expect(AuditEvent::query()->where('action', 'agent.command.expired')->where('subject_id', $expired->id)->exists())->toBeTrue();

    // A late completion from the agent is a terminal retry: 409 with the current state.
    $this->withHeaders(expiryHeaders($node))
        ->postJson("/api/agent/v1/commands/{$expired->id}/complete", ['result' => null])
        ->assertStatus(409)
        ->assertJsonPath('status', 'Expired');
    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed);
});

test('an unclaimed command past its availability deadline is expired', function (): void {
    $node = expiryNode();
    $project = Project::factory()->create();
    $deployment = Deployment::factory()->for($project)->create(['sequence' => 1, 'status' => DeploymentStatus::Queued]);
    $stale = AgentCommand::factory()->create([
        'type' => AgentCommandType::DeployProject, 'status' => AgentCommandStatus::Pending,
        'project_id' => $project->id, 'deployment_id' => $deployment->id, 'agent_node_id' => null,
        'payload' => [], 'expires_at' => now()->subMinute(),
    ]);
    $waiting = AgentCommand::factory()->create([
        'type' => AgentCommandType::DeployProject, 'status' => AgentCommandStatus::Pending,
        'agent_node_id' => null, 'payload' => [], 'expires_at' => null,
    ]);

    $this->artisan('agent:expire-commands')
        ->expectsOutputToContain('Expired 1 unclaimed and 0 leased agent command(s).');

    expect($stale->fresh()->status)->toBe(AgentCommandStatus::Expired)
        ->and($stale->fresh()->error_code)->toBe('command_expired')
        ->and($waiting->fresh()->status)->toBe(AgentCommandStatus::Pending)
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->failure_code)->toBe('command_expired');

    $this->actingAs($project->user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}")
        ->assertOk()
        ->assertJsonPath('data.failure.category', DeploymentFailureCategory::Scheduling->value);

    expect($node->fresh()->status)->toBe(AgentNodeStatus::Ready);
});

test('expiry never reopens or rewrites a deployment that is already closed', function (): void {
    $node = expiryNode();
    $project = Project::factory()->create();
    $deployment = Deployment::factory()->for($project)->create([
        'sequence' => 1, 'status' => DeploymentStatus::Succeeded, 'agent_node_id' => $node->id, 'finished_at' => now(),
    ]);
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::DeployProject, 'status' => AgentCommandStatus::Running,
        'project_id' => $project->id, 'deployment_id' => $deployment->id, 'agent_node_id' => $node->id,
        'payload' => [], 'lease_expires_at' => now()->subMinute(),
    ]);

    $this->artisan('agent:expire-commands')->expectsOutputToContain('Expired 0 unclaimed and 1 leased');

    expect($command->fresh()->status)->toBe(AgentCommandStatus::Expired)
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->fresh()->failure_code)->toBeNull();

    // Idempotent: nothing left to expire.
    $this->artisan('agent:expire-commands')->expectsOutputToContain('Expired 0 unclaimed and 0 leased');
});

test('an expired inspection marks the project preview failed unless superseded', function (): void {
    $node = expiryNode();
    $project = Project::factory()->create(['inspection_status' => ProjectInspectionStatus::Pending]);
    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::InspectProject, 'status' => AgentCommandStatus::Claimed,
        'project_id' => $project->id, 'agent_node_id' => $node->id,
        'payload' => [], 'lease_expires_at' => now()->subMinute(),
    ]);

    $this->artisan('agent:expire-commands');

    expect($project->fresh()->inspection_status)->toBe(ProjectInspectionStatus::Failed)
        ->and($project->fresh()->inspection_error_code)->toBe('command_lease_expired')
        ->and($command->fresh()->status)->toBe(AgentCommandStatus::Expired);
});

test('an expired node lifecycle command is audited without touching the desired state', function (): void {
    $node = expiryNode();
    $node->update(['desired_state' => AgentNodeDesiredState::Draining]);
    $drain = AgentCommand::factory()->create([
        'type' => AgentCommandType::DrainNode, 'status' => AgentCommandStatus::Claimed,
        'agent_node_id' => $node->id, 'lease_expires_at' => now()->subMinute(),
    ]);

    $this->artisan('agent:expire-commands')->expectsOutputToContain('1 leased');

    expect($drain->fresh()->status)->toBe(AgentCommandStatus::Expired)
        ->and($node->fresh()->desired_state)->toBe(AgentNodeDesiredState::Draining)
        ->and(AuditEvent::query()->where('action', 'agent.command.expired')->where('subject_id', $drain->id)->exists())->toBeTrue();
});

test('the expiry sweep is scheduled every minute', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'agent:expire-commands'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});
