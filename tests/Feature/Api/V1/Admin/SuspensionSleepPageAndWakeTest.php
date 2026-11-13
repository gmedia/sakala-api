<?php

declare(strict_types=1);

use App\Actions\Project\SleepIdleProjectsAction;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = adminApiAdmin();
});

/** A node from before idle sleep (agent v0.6 and older). */
function nodeWithoutIdleSleep(): AgentNode
{
    return idleSleepNode(['capabilities' => ['docker-runtime', 'caddy-file-routing']]);
}

function suspendProject(Project $project): TestResponse
{
    return test()->actingAs(test()->admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", ['reason' => 'Laporan phishing'])
        ->assertAccepted();
}

function unsuspendProject(Project $project, array $headers = []): TestResponse
{
    return test()->actingAs(test()->admin, 'web')
        ->withHeaders($headers)
        ->postJson("/api/v1/admin/projects/{$project->id}/unsuspend", ['reason' => 'Banding diterima'])
        ->assertAccepted();
}

/** The pending suspension sleep, reported done by its node. */
function completeSuspensionSleep(Project $project): void
{
    $sleep = AgentCommand::query()
        ->where('project_id', $project->id)
        ->where('type', AgentCommandType::SleepProject)
        ->where('status', AgentCommandStatus::Pending)
        ->sole();
    $sleep->update(['status' => AgentCommandStatus::Running]);

    test()->withHeaders(idleSleepHeaders($sleep->agentNode))
        ->postJson("/api/agent/v1/commands/{$sleep->id}/complete", ['result' => null])
        ->assertNoContent();
}

/**
 * A project suspended on `$node` whose suspension sleep finished: stopped,
 * without an active deployment, and the container kept on the node.
 *
 * @return array{Project, Deployment}
 */
function suspendedAndAsleep(AgentNode $node, array $projectOverrides = [], ?User $owner = null): array
{
    [$project, $deployment] = idleSleepProject($node, $projectOverrides, owner: $owner);
    suspendProject($project);
    completeSuspensionSleep($project);

    $project->refresh();
    expect($project->runtime_status)->toBe(RuntimeStatus::Stopped)
        ->and($project->active_deployment_id)->toBeNull();

    return [$project, $deployment];
}

function wakeCommandsOf(Project $project)
{
    return AgentCommand::query()->where('project_id', $project->id)->where('type', AgentCommandType::WakeProject);
}

test('a suspension sleep asks an idle-sleep node for the unavailable page', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);

    suspendProject($project)->assertJsonPath('data.command.type', 'SleepProject');

    $sleep = AgentCommand::query()->where('type', AgentCommandType::SleepProject)->sole();
    expect($sleep->payload)->toBe(['page' => 'unavailable']);

    $this->withHeaders(idleSleepHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonPath('data.0.id', $sleep->id)
        ->assertJsonPath('data.0.deployment_id', $deployment->id)
        ->assertJsonPath('data.0.payload', ['page' => 'unavailable']);
});

test('a node without idle-sleep gets an empty payload for a suspension sleep', function (): void {
    $node = nodeWithoutIdleSleep();
    [$project] = idleSleepProject($node);

    suspendProject($project);

    $sleep = AgentCommand::query()->where('type', AgentCommandType::SleepProject)->sole();
    expect($sleep->payload)->toBe([]);

    $poll = $this->withHeaders(idleSleepHeaders($node))->getJson('/api/agent/v1/commands')->assertOk();

    expect($poll->json('data.0.id'))->toBe($sleep->id)
        // An object, as for every command without payload, never a list.
        ->and($poll->getContent())->toContain('"payload":{}')
        ->not->toContain('page');
});

test('a deployment that finishes after the suspension gets the same page rule', function (array $capabilities, array $payload): void {
    $node = idleSleepNode(['capabilities' => $capabilities]);
    [$project] = idleSleepProject($node);
    $deploy = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::HealthChecking,
        'sequence' => 2,
    ]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deploy->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Running,
    ]);
    // The suspension of the first deployment already finished.
    suspendProject($project);
    completeSuspensionSleep($project);

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();

    $sleep = AgentCommand::query()
        ->where('type', AgentCommandType::SleepProject)
        ->where('deployment_id', $deploy->id)
        ->sole();

    expect($sleep->request_context['reason'])->toBe('deployment_completed_after_suspend')
        ->and($sleep->payload)->toBe($payload);
})->with([
    'idle-sleep node' => [['docker-runtime', 'dockerfile-build', SleepIdleProjectsAction::CAPABILITY], ['page' => 'unavailable']],
    'older node' => [['docker-runtime', 'dockerfile-build'], []],
]);

test('idle sleeps keep the empty payload, so the node shows its waking page', function (): void {
    config(['sakala.project.idle_sleep_minutes' => 30]);
    $node = idleSleepNode();
    idleSleepProject($node, ['last_request_at' => now()->subHour()]);

    $this->artisan('projects:sleep-idle')->assertSuccessful();

    expect(AgentCommand::query()->where('type', AgentCommandType::SleepProject)->sole()->payload)->toBe([]);
});

test('unsuspending wakes the kept container once, and the wake brings back the deployment', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = suspendedAndAsleep($node);

    unsuspendProject($project, ['Idempotency-Key' => 'unsuspend-1'])
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.runtime_status', 'stopped')
        ->assertJsonPath('data.command.type', 'WakeProject')
        ->assertJsonPath('data.wake', 'requested');

    $wake = wakeCommandsOf($project)->sole();

    expect($wake->deployment_id)->toBe($deployment->id)
        ->and($wake->agent_node_id)->toBe($node->id)
        ->and($wake->status)->toBe(AgentCommandStatus::Pending)
        ->and($wake->request_context)->toBe([
            'reason' => 'unsuspended',
            'source' => 'unsuspend',
            'actor_type' => User::class,
            'actor_id' => (string) $this->admin->id,
        ]);

    expect(AuditEvent::query()->where('action', 'project.unsuspended')->sole()->metadata)->toMatchArray([
        'wake' => 'requested',
        'wake_command_id' => $wake->id,
    ])->and(AuditEvent::query()->where('action', 'project.wake_requested')->sole()->metadata)->toMatchArray([
        'source' => 'unsuspend',
        'command_id' => $wake->id,
        'deployment_id' => $deployment->id,
    ]);

    // The same request again replays the answer and queues nothing new.
    unsuspendProject($project, ['Idempotency-Key' => 'unsuspend-1'])
        ->assertJsonPath('data.command.id', $wake->id)
        ->assertJsonPath('data.wake', 'requested');
    expect(wakeCommandsOf($project)->count())->toBe(1);

    $this->withHeaders(idleSleepHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertJsonPath('data.0.id', $wake->id);
    $wake->update(['status' => AgentCommandStatus::Running]);
    $this->withHeaders(idleSleepHeaders($node))
        ->postJson("/api/agent/v1/commands/{$wake->id}/complete", ['result' => null])
        ->assertNoContent();

    $project->refresh();

    expect($project->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($project->active_deployment_id)->toBe($deployment->id)
        ->and($project->status)->toBe(ProjectStatus::Active);
});

test('unsuspending a full workspace leaves the project stopped and says why', function (): void {
    $node = idleSleepNode();
    $owner = User::factory()->create();
    $team = teamWorkspace($owner, attributes: ['limit_profile_id' => LimitProfile::factory()->create(['max_active_containers' => 1])->id]);
    [$project] = suspendedAndAsleep($node, ['workspace_id' => $team->id], owner: $owner);
    idleSleepProject($node, ['workspace_id' => $team->id], owner: $owner);

    unsuspendProject($project)
        ->assertJsonPath('data.command', null)
        ->assertJsonPath('data.wake', 'container_limit_reached');

    expect(wakeCommandsOf($project)->count())->toBe(0)
        ->and($project->fresh()->runtime_status)->toBe(RuntimeStatus::Stopped)
        ->and(AuditEvent::query()->where('action', 'project.unsuspended')->sole()->metadata['wake'])->toBe('container_limit_reached');
});

test('unsuspending without a kept container changes nothing at runtime', function (callable $setup): void {
    $project = $setup();

    unsuspendProject($project)
        ->assertJsonPath('data.command', null)
        ->assertJsonPath('data.wake', 'nothing_to_wake');

    expect(wakeCommandsOf($project)->count())->toBe(0);
})->with([
    'never deployed' => [function (): Project {
        $project = Project::factory()->create();
        suspendProject($project);

        return $project;
    }],
    'stopped before the suspension' => [function (): Project {
        $node = idleSleepNode();
        [$project, $deployment] = idleSleepProject($node, ['runtime_status' => RuntimeStatus::Stopped, 'active_deployment_id' => null]);
        AgentCommand::factory()->create([
            'project_id' => $project->id,
            'deployment_id' => $deployment->id,
            'agent_node_id' => $node->id,
            'type' => AgentCommandType::StopProject,
            'status' => AgentCommandStatus::Succeeded,
            'completed_at' => now()->subHour(),
        ]);
        $project->update(['active_deployment_id' => null]);
        suspendProject($project);
        // The node has nothing left to put to sleep.
        AgentCommand::query()->where('type', AgentCommandType::SleepProject)->sole()->update([
            'status' => AgentCommandStatus::Failed,
            'failed_at' => now(),
            'error_code' => AgentCommand::WORKLOAD_NOT_FOUND_ERROR,
        ]);

        return $project->fresh();
    }],
    'suspension sleep failed, still running' => [function (): Project {
        [$project] = idleSleepProject(idleSleepNode(), ['status' => ProjectStatus::Suspended]);

        return $project;
    }],
]);

test('unsuspending on a node without idle-sleep does not send it a wake', function (): void {
    [$project] = suspendedAndAsleep(nodeWithoutIdleSleep());

    unsuspendProject($project)
        ->assertJsonPath('data.command', null)
        ->assertJsonPath('data.wake', 'node_cannot_wake');

    expect(wakeCommandsOf($project)->count())->toBe(0);
});

test('other control actions answer without a wake outcome', function (): void {
    [$project] = idleSleepProject(idleSleepNode());

    suspendProject($project)->assertJsonPath('data.wake', null);
});

test('a wake still queued from before the suspension is reused, not doubled', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = suspendedAndAsleep($node);
    $queued = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::WakeProject,
        'status' => AgentCommandStatus::Pending,
    ]);

    unsuspendProject($project)
        ->assertJsonPath('data.command.id', $queued->id)
        ->assertJsonPath('data.wake', 'requested');

    expect(wakeCommandsOf($project)->count())->toBe(1);
});
