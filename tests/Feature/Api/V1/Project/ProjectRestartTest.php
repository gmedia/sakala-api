<?php

declare(strict_types=1);

use App\Actions\Project\RestartProjectAction;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Events\Deployment\DeploymentUpdated;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * A team project live on a node with `$replicas` replicas.
 *
 * @return array{team: array{workspace: Workspace, owner: User, member: User, viewer: User, outsider: User, admin: User}, project: Project, deployment: Deployment, node: AgentNode}
 */
function restartSetup(RuntimeStatus $runtime = RuntimeStatus::Running, int $replicas = 2): array
{
    $node = rollbackNode();
    $team = databaseTeam();
    $project = Project::factory()->inWorkspace($team['workspace'], $team['owner'])->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => $runtime,
        'replicas' => $replicas,
    ]);
    $deployment = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
        'replicas' => $replicas,
        'finished_at' => now()->subHour(),
    ]);
    $project->update(['active_deployment_id' => $deployment->id]);

    return ['team' => $team, 'project' => $project->fresh(), 'deployment' => $deployment, 'node' => $node];
}

function restartUrl(Project $project): string
{
    return "/api/v1/app/projects/{$project->id}/restart";
}

function restartCommand(Project $project): AgentCommand
{
    return AgentCommand::query()->where('project_id', $project->id)->where('type', AgentCommandType::RestartProject)->sole();
}

/** The node claims the restart and answers `complete` or `fail`. */
function restartAnswer(AgentNode $node, AgentCommand $command, string $outcome): void
{
    test()->withHeaders(databaseNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();

    $outcome === 'complete'
        ? test()->withHeaders(databaseNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])->assertNoContent()
        : test()->withHeaders(databaseNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/fail", [
            'error_code' => 'runtime_restart_failed',
            'error_message' => 'docker restart failed',
        ])->assertNoContent();
}

// ─── Requesting a restart ────────────────────────────────────────────────────

test('a member restarts the live containers: 202, a RestartProject on its node, and no deployment', function (): void {
    ['team' => $team, 'project' => $project, 'deployment' => $deployment, 'node' => $node] = restartSetup();

    $response = $this->actingAs($team['member'], 'web')->postJson(restartUrl($project))->assertAccepted();
    $command = restartCommand($project);

    $response->assertExactJson(['data' => [
        'project_id' => $project->id,
        'status' => 'active',
        'runtime_status' => 'running',
        'command' => ['id' => $command->id, 'type' => 'RestartProject', 'status' => 'Pending'],
        'wake' => null,
    ]]);

    expect($command->deployment_id)->toBe($deployment->id)
        ->and($command->agent_node_id)->toBe($node->id)
        ->and($command->payload)->toBe([])
        ->and($command->request_context)->toMatchArray(['reason' => RestartProjectAction::REASON, 'actor_id' => (string) $team['member']->id])
        ->and(Deployment::query()->whereBelongsTo($project)->count())->toBe(1);

    $audit = AuditEvent::query()->where('action', RestartProjectAction::REQUESTED_AUDIT_ACTION)->sole();
    expect($audit->actor_type)->toBe(User::class)
        ->and((string) $audit->actor_id)->toBe((string) $team['member']->id)
        ->and($audit->subject_id)->toBe($project->id)
        ->and($audit->metadata['command_id'])->toBe($command->id);

    $this->actingAs($team['viewer'], 'web')->getJson("/api/v1/app/projects/{$project->id}")
        ->assertJsonPath('data.restart', ['requested_at' => now()->toAtomString(), 'status' => 'pending']);
});

test('restarting takes the rights of deploying', function (string $role, int $status): void {
    ['team' => $team, 'project' => $project] = restartSetup();

    $response = $this->actingAs($team[$role], 'web')->postJson(restartUrl($project))->assertStatus($status);

    if ($status === 403) {
        expect($response->json('code'))->toBeNull()
            ->and(AgentCommand::query()->where('type', AgentCommandType::RestartProject)->exists())->toBeFalse();
    }
})->with([
    'owner' => ['owner', 202],
    'admin' => ['admin', 202],
    'viewer' => ['viewer', 403],
    'outsider' => ['outsider', 403],
]);

test('a suspended project tells its members why and keeps outsiders at a plain 403', function (): void {
    ['team' => $team, 'project' => $project] = restartSetup();
    $project->update(['status' => ProjectStatus::Suspended]);

    $this->actingAs($team['member'], 'web')->postJson(restartUrl($project))
        ->assertStatus(409)
        ->assertJsonPath('code', 'PROJECT_SUSPENDED');
    expect($this->actingAs($team['outsider'], 'web')->postJson(restartUrl($project))->assertForbidden()->json('code'))->toBeNull();
});

test('a restart needs the current legal documents', function (): void {
    ['team' => $team, 'project' => $project] = restartSetup();
    $team['member']->legalAcceptances()->delete();

    $this->actingAs($team['member'], 'web')->postJson(restartUrl($project))
        ->assertForbidden()
        ->assertJsonPath('code', 'LEGAL_ACCEPTANCE_REQUIRED');
});

test('a crashed project can be restarted', function (): void {
    ['team' => $team, 'project' => $project] = restartSetup(RuntimeStatus::Crashed);

    $this->actingAs($team['owner'], 'web')->postJson(restartUrl($project))
        ->assertAccepted()
        ->assertJsonPath('data.runtime_status', 'crashed');
});

test('only a running or crashed project can be restarted', function (RuntimeStatus $runtime, bool $keepsActive): void {
    ['team' => $team, 'project' => $project] = restartSetup($runtime);

    if (! $keepsActive) {
        $project->update(['active_deployment_id' => null]);
    }

    $this->actingAs($team['owner'], 'web')->postJson(restartUrl($project))
        ->assertStatus(409)
        ->assertJsonPath('code', 'PROJECT_NOT_RUNNING')
        ->assertJsonPath('runtime_status', $runtime->value);

    expect(AgentCommand::query()->where('type', AgentCommandType::RestartProject)->exists())->toBeFalse();
})->with([
    'sleeping' => [RuntimeStatus::Sleeping, true],
    'stopped' => [RuntimeStatus::Stopped, false],
    'never deployed' => [RuntimeStatus::NotDeployed, false],
    'running without an active deployment' => [RuntimeStatus::Running, false],
]);

test('a project that is changing cannot be restarted', function (string $case): void {
    ['team' => $team, 'project' => $project, 'deployment' => $deployment] = restartSetup();

    match ($case) {
        'queued deployment' => Deployment::factory()->for($project)->create(['sequence' => 2, 'status' => DeploymentStatus::Queued]),
        'running deployment' => Deployment::factory()->for($project)->create(['sequence' => 2, 'status' => DeploymentStatus::Building]),
        'open wake' => AgentCommand::factory()->create(['project_id' => $project->id, 'deployment_id' => $deployment->id, 'type' => AgentCommandType::WakeProject, 'status' => AgentCommandStatus::Pending]),
        'open stop' => AgentCommand::factory()->create(['project_id' => $project->id, 'deployment_id' => $deployment->id, 'type' => AgentCommandType::StopProject, 'status' => AgentCommandStatus::Running]),
        'open restart' => $this->actingAs($team['owner'], 'web')->postJson(restartUrl($project))->assertAccepted(),
    };

    $this->actingAs($team['member'], 'web')->postJson(restartUrl($project))
        ->assertStatus(409)
        ->assertJsonPath('code', 'PROJECT_RUNTIME_BUSY');
})->with(['queued deployment', 'running deployment', 'open wake', 'open stop', 'open restart']);

test('the same idempotency key returns the same restart; another member or project with it conflicts', function (): void {
    ['team' => $team, 'project' => $project] = restartSetup();
    $headers = ['Idempotency-Key' => 'restart-key-1'];

    $first = $this->actingAs($team['member'], 'web')->withHeaders($headers)->postJson(restartUrl($project))->assertAccepted();
    $again = $this->actingAs($team['member'], 'web')->withHeaders($headers)->postJson(restartUrl($project))->assertAccepted();

    expect($again->json('data.command.id'))->toBe($first->json('data.command.id'))
        ->and(AgentCommand::query()->where('type', AgentCommandType::RestartProject)->count())->toBe(1);

    $this->actingAs($team['owner'], 'web')->withHeaders($headers)->postJson(restartUrl($project))->assertStatus(409);

    ['team' => $otherTeam, 'project' => $other] = restartSetup();
    $this->actingAs($otherTeam['owner'], 'web')->withHeaders($headers)->postJson(restartUrl($other))->assertStatus(409);

    expect(AgentCommand::query()->where('type', AgentCommandType::RestartProject)->count())->toBe(1);
});

// ─── Completion ──────────────────────────────────────────────────────────────

test('a completed restart is audited, clears the pending restart, and is announced although the project was running', function (): void {
    ['team' => $team, 'project' => $project, 'deployment' => $deployment, 'node' => $node] = restartSetup();
    $this->actingAs($team['member'], 'web')->postJson(restartUrl($project))->assertAccepted();
    $command = restartCommand($project);

    Event::fake([DeploymentUpdated::class]);
    restartAnswer($node, $command, 'complete');

    Event::assertDispatched(DeploymentUpdated::class, fn (DeploymentUpdated $event): bool => $event->broadcastWith()['deployment_id'] === $deployment->id
        && $event->broadcastWith()['project_runtime_status'] === 'running'
        && $event->broadcastWith()['restart'] === ['command_id' => $command->id, 'outcome' => 'succeeded']);
    Event::assertDispatchedTimes(DeploymentUpdated::class, 1);

    $audit = AuditEvent::query()->where('action', RestartProjectAction::COMPLETED_AUDIT_ACTION)->sole();
    expect($audit->actor_type)->toBe(AgentNode::class)
        ->and($audit->metadata)->toMatchArray(['command_id' => $command->id, 'deployment_id' => $deployment->id, 'outcome' => 'succeeded', 'applied' => true])
        ->and($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($project->fresh()->active_deployment_id)->toBe($deployment->id);

    $this->actingAs($team['viewer'], 'web')->getJson("/api/v1/app/projects/{$project->id}")->assertJsonPath('data.restart', null);
});

test('a restart of a crashed project brings it back to running', function (): void {
    ['team' => $team, 'project' => $project, 'node' => $node] = restartSetup(RuntimeStatus::Crashed);
    $this->actingAs($team['member'], 'web')->postJson(restartUrl($project))->assertAccepted();

    Event::fake([DeploymentUpdated::class]);
    restartAnswer($node, restartCommand($project), 'complete');

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running);
    Event::assertDispatched(DeploymentUpdated::class, fn (DeploymentUpdated $event): bool => $event->broadcastWith()['project_runtime_status'] === 'running'
        && $event->broadcastWith()['restart']['outcome'] === 'succeeded');
});

test('a failed restart is audited, clears the pending restart, keeps the runtime state, and is announced', function (): void {
    ['team' => $team, 'project' => $project, 'deployment' => $deployment, 'node' => $node] = restartSetup(RuntimeStatus::Crashed);
    $this->actingAs($team['member'], 'web')->postJson(restartUrl($project))->assertAccepted();
    $command = restartCommand($project);

    Event::fake([DeploymentUpdated::class]);
    restartAnswer($node, $command, 'fail');

    Event::assertDispatched(DeploymentUpdated::class, fn (DeploymentUpdated $event): bool => $event->broadcastWith()['deployment_id'] === $deployment->id
        && $event->broadcastWith()['project_runtime_status'] === 'crashed'
        && $event->broadcastWith()['restart'] === ['command_id' => $command->id, 'outcome' => 'failed']);

    $audit = AuditEvent::query()->where('action', RestartProjectAction::FAILED_AUDIT_ACTION)->sole();
    expect($audit->metadata)->toMatchArray(['command_id' => $command->id, 'error_code' => 'runtime_restart_failed'])
        ->and($project->fresh()->runtime_status)->toBe(RuntimeStatus::Crashed);

    $this->actingAs($team['viewer'], 'web')->getJson("/api/v1/app/projects/{$project->id}")->assertJsonPath('data.restart', null);
});

test('a restart the node never finished ends like a failed one', function (): void {
    ['team' => $team, 'project' => $project, 'node' => $node] = restartSetup();
    $this->actingAs($team['member'], 'web')->postJson(restartUrl($project))->assertAccepted();
    $command = restartCommand($project);
    $this->withHeaders(databaseNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();

    Event::fake([DeploymentUpdated::class]);
    $command->update(['lease_expires_at' => now()->subMinute()]);
    $this->artisan('agent:expire-commands')->assertSuccessful();

    expect($command->fresh()->status)->toBe(AgentCommandStatus::Expired);
    Event::assertDispatched(DeploymentUpdated::class, fn (DeploymentUpdated $event): bool => $event->broadcastWith()['restart'] === ['command_id' => $command->id, 'outcome' => 'failed']);
    $this->actingAs($team['viewer'], 'web')->getJson("/api/v1/app/projects/{$project->id}")->assertJsonPath('data.restart', null);
});

// ─── Metrics ─────────────────────────────────────────────────────────────────

test('a succeeded restart counts one restart per replica in the minute series', function (): void {
    ['team' => $team, 'project' => $project, 'deployment' => $deployment, 'node' => $node] = restartSetup(replicas: 2);
    $minute = CarbonImmutable::now()->startOfMinute();

    foreach ([0, 1] as $replica) {
        metricMinuteRow($deployment, $minute->subMinute(), ['replica' => $replica, 'restart_count' => 3]);
        metricMinuteRow($deployment, $minute, ['replica' => $replica, 'restart_count' => 3]);
    }

    $this->actingAs($team['member'], 'web')->postJson(restartUrl($project))->assertAccepted();
    restartAnswer($node, restartCommand($project), 'complete');

    // A failed restart counts nothing.
    $failed = AgentCommand::factory()->create([
        'project_id' => $project->id, 'deployment_id' => $deployment->id, 'agent_node_id' => $node->id,
        'type' => AgentCommandType::RestartProject, 'status' => AgentCommandStatus::Failed, 'completed_at' => now(),
    ]);
    expect($failed->status)->toBe(AgentCommandStatus::Failed);

    $response = $this->actingAs($team['viewer'], 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/metrics?range=1h&per_replica=1")
        ->assertOk();

    $restarts = collect($response->json('data.series.restarts'))->pluck('count', 't')->all();
    expect($restarts)->toBe([
        $minute->subMinute()->toIso8601String() => 0,
        $minute->toIso8601String() => 2,
    ]);

    foreach ($response->json('data.series.per_replica') as $replica) {
        expect(collect($replica['restarts'])->pluck('count', 't')->all()[$minute->toIso8601String()])->toBe(1);
    }
});

test('a succeeded restart counts in the hourly series too', function (): void {
    ['team' => $team, 'project' => $project, 'deployment' => $deployment, 'node' => $node] = restartSetup(replicas: 2);
    $hour = CarbonImmutable::now()->startOfHour();

    foreach ([0, 1] as $replica) {
        metricHourRow($deployment, $hour, ['replica' => $replica]);
    }

    $this->actingAs($team['member'], 'web')->postJson(restartUrl($project))->assertAccepted();
    restartAnswer($node, restartCommand($project), 'complete');

    $restarts = collect($this->actingAs($team['viewer'], 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/metrics?range=7d")
        ->assertOk()
        ->json('data.series.restarts'))->pluck('count', 't')->all();

    expect($restarts)->toBe([$hour->toIso8601String() => 2]);
});
