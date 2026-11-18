<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Events\Deployment\DeploymentUpdated;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function completeIdleCommand(AgentCommand $command): void
{
    test()->withHeaders(idleSleepHeaders($command->agentNode))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();
}

function failIdleCommand(AgentCommand $command, string $code = 'runtime_unavailable'): void
{
    test()->withHeaders(idleSleepHeaders($command->agentNode))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", [
            'error_code' => $code,
            'error_message' => 'The node could not do it.',
        ])
        ->assertNoContent();
}

function auditActions(Project $project): array
{
    return AuditEvent::query()->where('subject_id', $project->id)->orderBy('id')->pluck('action')->all();
}

test('a completed idle sleep makes the project sleep, keeps its deployment, and broadcasts', function (): void {
    Event::fake([DeploymentUpdated::class]);
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);
    $command = idleSleepCommand($project, $deployment);

    completeIdleCommand($command);

    $project->refresh();

    expect($project->runtime_status)->toBe(RuntimeStatus::Sleeping)
        ->and($project->active_deployment_id)->toBe($deployment->id)
        ->and($project->status)->toBe(ProjectStatus::Active)
        ->and(auditActions($project))->toBe(['project.idle_sleep_completed']);

    Event::assertDispatched(
        DeploymentUpdated::class,
        fn (DeploymentUpdated $event): bool => $event->broadcastWith()['deployment_id'] === $deployment->id
            && $event->broadcastWith()['project_runtime_status'] === 'sleeping',
    );

    $this->actingAs(User::query()->findOrFail($project->user_id), 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.runtime_status', 'sleeping')
        ->assertJsonPath('data.active_deployment_id', $deployment->id)
        ->assertJsonPath('data.serving_port', null);
});

test('a suspension sleep still stops the project and clears its deployment', function (?User $actor): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node, ['status' => ProjectStatus::Suspended]);
    // An admin who writes "idle" as the reason still suspends.
    $command = idleSleepCommand($project, $deployment, overrides: ['request_context' => [
        'reason' => 'idle',
        'actor_type' => $actor !== null ? User::class : null,
        'actor_id' => $actor?->id !== null ? (string) $actor->id : null,
    ]]);

    expect($command->isIdleSleep())->toBeFalse();

    completeIdleCommand($command);

    $project->refresh();

    expect($project->runtime_status)->toBe(RuntimeStatus::Stopped)
        ->and($project->active_deployment_id)->toBeNull()
        ->and(auditActions($project))->toBe(['project.suspend_completed']);
})->with([
    'admin' => fn () => adminApiAdmin(),
    'deployment completed after suspend' => [null],
]);

test('an idle sleep that completes after a suspension acts as the suspension', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node, ['status' => ProjectStatus::Suspended]);

    completeIdleCommand(idleSleepCommand($project, $deployment));

    $project->refresh();

    expect($project->runtime_status)->toBe(RuntimeStatus::Stopped)
        ->and($project->active_deployment_id)->toBeNull()
        ->and(auditActions($project))->toBe(['project.idle_sleep_completed']);
});

test('an idle sleep does not undo a stop that finished first', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);
    $project->update(['runtime_status' => RuntimeStatus::Stopped, 'active_deployment_id' => null]);

    completeIdleCommand(idleSleepCommand($project, $deployment));

    $project->refresh();

    expect($project->runtime_status)->toBe(RuntimeStatus::Stopped)
        ->and($project->active_deployment_id)->toBeNull();
});

test('an idle sleep of a superseded deployment changes nothing', function (): void {
    $node = idleSleepNode();
    [$project, $old] = idleSleepProject($node);
    $current = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 2,
        'finished_at' => now(),
    ]);
    $project->update(['active_deployment_id' => $current->id]);

    completeIdleCommand(idleSleepCommand($project, $old));

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($project->fresh()->active_deployment_id)->toBe($current->id);
});

test('a deployment that starts after the sleep owns the status, and failing falls back to sleeping', function (DeploymentStatus $outcome): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);
    $sleep = idleSleepCommand($project, $deployment);
    $attempt = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Building,
        'sequence' => 2,
    ]);
    $project->update(['runtime_status' => RuntimeStatus::Deploying]);

    completeIdleCommand($sleep);

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Deploying);

    $deploy = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $attempt->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Running,
    ]);

    failIdleCommand($deploy, $outcome === DeploymentStatus::Cancelled ? 'runtime_cancelled' : 'build_failed');

    expect($attempt->fresh()->status)->toBe($outcome)
        ->and($project->fresh()->runtime_status)->toBe(RuntimeStatus::Sleeping)
        ->and($project->fresh()->active_deployment_id)->toBe($deployment->id);
})->with([DeploymentStatus::Failed, DeploymentStatus::Cancelled]);

test('a failed redeploy of a sleeping project leaves it sleeping', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = sleepingIdleProject($node);
    $attempt = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Building,
        'sequence' => 2,
    ]);
    $project->update(['runtime_status' => RuntimeStatus::Deploying]);
    $deploy = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $attempt->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Running,
    ]);

    failIdleCommand($deploy, 'build_failed');

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Sleeping)
        ->and($project->fresh()->active_deployment_id)->toBe($deployment->id);
});

test('a failed idle sleep keeps the project running and is audited as such', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);

    failIdleCommand(idleSleepCommand($project, $deployment));

    $audit = AuditEvent::query()->where('subject_id', $project->id)->sole();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($audit->action)->toBe('project.idle_sleep_failed')
        ->and($audit->metadata['error_code'])->toBe('runtime_unavailable');
});

test('a missing container is audited once, even when the node reports the failure twice', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);
    $command = idleSleepCommand($project, $deployment);

    failIdleCommand($command, AgentCommand::WORKLOAD_NOT_FOUND_ERROR);
    failIdleCommand($command, AgentCommand::WORKLOAD_NOT_FOUND_ERROR);

    $audit = AuditEvent::query()->where('subject_id', $project->id)->sole();

    expect($audit->action)->toBe('project.idle_sleep_failed')
        ->and($audit->metadata)->toMatchArray([
            'command_id' => $command->id,
            'deployment_id' => $deployment->id,
            'error_code' => 'runtime_workload_not_found',
        ])
        ->and($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($project->fresh()->isIdleSleepBlocked())->toBeTrue();
});

test('a failed suspension sleep is still audited as a suspension', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node, ['status' => ProjectStatus::Suspended]);
    $command = idleSleepCommand($project, $deployment, overrides: ['request_context' => ['reason' => 'abuse', 'actor_type' => User::class, 'actor_id' => '1']]);

    failIdleCommand($command);

    expect(auditActions($project))->toBe(['project.suspend_failed']);
});

test('a completed wake makes a sleeping project run again, audits, and broadcasts', function (): void {
    Event::fake([DeploymentUpdated::class]);
    $node = idleSleepNode();
    [$project, $deployment] = sleepingIdleProject($node);
    $wake = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::WakeProject,
        'status' => AgentCommandStatus::Running,
    ]);

    completeIdleCommand($wake);

    $project->refresh();
    $audit = AuditEvent::query()->where('action', 'project.wake_completed')->sole();

    expect($project->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($project->active_deployment_id)->toBe($deployment->id)
        ->and($audit->subject_id)->toBe($project->id)
        ->and($audit->metadata)->toMatchArray(['command_id' => $wake->id, 'applied' => true]);

    Event::assertDispatched(
        DeploymentUpdated::class,
        fn (DeploymentUpdated $event): bool => $event->broadcastWith()['project_runtime_status'] === 'running',
    );
});

test('a wake that completes on a suspended project does not run it', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = sleepingIdleProject($node, ['status' => ProjectStatus::Suspended]);
    $wake = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::WakeProject,
        'status' => AgentCommandStatus::Running,
    ]);

    completeIdleCommand($wake);

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Sleeping)
        ->and(AuditEvent::query()->where('action', 'project.wake_completed')->sole()->metadata['applied'])->toBeFalse();
});

test('a failed wake keeps the project sleeping and records the failure code', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = sleepingIdleProject($node);
    $wake = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::WakeProject,
        'status' => AgentCommandStatus::Claimed,
    ]);

    failIdleCommand($wake, 'runtime_capacity_exceeded');

    $audit = AuditEvent::query()->where('action', 'project.wake_failed')->sole();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Sleeping)
        ->and($project->fresh()->active_deployment_id)->toBe($deployment->id)
        ->and($audit->subject_id)->toBe($project->id)
        ->and($audit->metadata)->toMatchArray(['command_id' => $wake->id, 'error_code' => 'runtime_capacity_exceeded']);
});

test('a crash report for a sleeping container does not read as a crash', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = sleepingIdleProject($node);
    $payload = heartbeatPayload();
    $payload['metadata']['workloads']['crashed_details'] = [[
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'exit_code' => 0,
        'oom_killed' => false,
        'finished_at' => now()->toIso8601ZuluString(),
    ]];
    $payload['metadata']['detail_counts']['crashed_details'] = 1;

    $this->withHeaders(idleSleepHeaders($node))->postJson('/api/agent/v1/heartbeat', $payload)->assertOk();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Sleeping);
});

test('an open idle sleep does not hold up a suspension', function (): void {
    $admin = adminApiAdmin();
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);
    idleSleepCommand($project, $deployment, AgentCommandStatus::Pending);

    $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", ['reason' => 'Abuse report'])
        ->assertStatus(202)
        ->assertJsonPath('data.command.type', 'SleepProject');

    expect($project->fresh()->status)->toBe(ProjectStatus::Suspended)
        ->and(AgentCommand::query()->where('type', AgentCommandType::SleepProject)->count())->toBe(2);
});

test('a pending suspension sleep still holds up a second suspension', function (): void {
    $admin = adminApiAdmin();
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);
    idleSleepCommand($project, $deployment, AgentCommandStatus::Pending, ['request_context' => [
        'reason' => 'earlier', 'actor_type' => User::class, 'actor_id' => (string) $admin->id,
    ]]);

    $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", ['reason' => 'Abuse report'])
        ->assertConflict();
});

test('an open idle sleep does not hold up lifting a suspension', function (): void {
    $admin = adminApiAdmin();
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node, ['status' => ProjectStatus::Suspended]);
    idleSleepCommand($project, $deployment, AgentCommandStatus::Claimed);

    $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/unsuspend", ['reason' => 'Appeal accepted'])
        ->assertStatus(202);

    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('suspending a sleeping project stops it for good and wake is refused', function (): void {
    $admin = adminApiAdmin();
    $node = idleSleepNode();
    [$project, $deployment] = sleepingIdleProject($node);

    $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", ['reason' => 'Abuse report'])
        ->assertStatus(202)
        ->assertJsonPath('data.runtime_status', 'sleeping');

    $sleep = AgentCommand::query()
        ->where('type', AgentCommandType::SleepProject)
        ->where('status', AgentCommandStatus::Pending)
        ->sole();

    expect($sleep->deployment_id)->toBe($deployment->id)
        ->and($sleep->isIdleSleep())->toBeFalse();

    // Even before the node reports back, nobody can wake it.
    $this->postJson("/api/v1/public/projects/{$project->slug}/wake")->assertStatus(202);
    expect(AgentCommand::query()->where('type', AgentCommandType::WakeProject)->count())->toBe(0);

    $sleep->update(['status' => AgentCommandStatus::Running]);
    completeIdleCommand($sleep);

    $project->refresh();

    expect($project->runtime_status)->toBe(RuntimeStatus::Stopped)
        ->and($project->active_deployment_id)->toBeNull()
        ->and($project->status)->toBe(ProjectStatus::Suspended);
});
