<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Exceptions\Runtime\ActiveContainerLimitExceededException;
use App\Models\AgentCommand;
use App\Models\Deployment;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Models\User;
use App\Services\Runtime\PilotRuntimeLimitService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function stopsOf(Project $project): Builder
{
    return AgentCommand::query()->where('project_id', $project->id)->where('type', AgentCommandType::StopProject);
}

/**
 * A suspended project whose suspension sleep succeeded: nothing serves and the
 * pointer is gone, but the node still keeps the container.
 *
 * @return array{Project, Deployment, User}
 */
function suspendedKeptProject(): array
{
    $node = idleSleepNode();
    $owner = User::factory()->create();
    [$project, $deployment] = idleSleepProject($node, ['status' => ProjectStatus::Suspended], owner: $owner);
    AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::SleepProject,
        'status' => AgentCommandStatus::Succeeded,
        'completed_at' => now()->subMinute(),
        'request_context' => ['reason' => 'abuse', 'actor_type' => User::class, 'actor_id' => '1'],
    ]);
    $project->update(['runtime_status' => RuntimeStatus::Stopped, 'active_deployment_id' => null]);

    return [$project->fresh(), $deployment, $owner];
}

// ─── Delete and purge ────────────────────────────────────────────────────────

test('deleting a sleeping project removes the container the node keeps', function (): void {
    $node = idleSleepNode();
    $owner = User::factory()->create();
    [$project, $deployment] = sleepingIdleProject($node, owner: $owner);

    $this->actingAs($owner, 'web')->deleteJson("/api/v1/app/projects/{$project->id}")->assertNoContent();

    $stop = stopsOf($project)->sole();

    expect($stop->deployment_id)->toBe($deployment->id)
        ->and($stop->agent_node_id)->toBe($node->id)
        ->and($stop->payload)->toBe(['remove_images' => true]);

    // Once the node confirms, the project reads stopped and the purge may go on.
    $stop->update(['status' => AgentCommandStatus::Running]);
    $this->withHeaders(idleSleepHeaders($node))
        ->postJson("/api/agent/v1/commands/{$stop->id}/complete", ['result' => null])
        ->assertNoContent();

    $trashed = Project::withTrashed()->findOrFail($project->id);

    expect($trashed->runtime_status)->toBe(RuntimeStatus::Stopped)
        ->and($trashed->active_deployment_id)->toBeNull();
});

test('deleting a suspended project whose container was kept removes it too', function (): void {
    [$project, $deployment, $owner] = suspendedKeptProject();

    $this->actingAs($owner, 'web')->deleteJson("/api/v1/app/projects/{$project->id}")->assertNoContent();

    $stop = stopsOf($project)->sole();

    expect($stop->deployment_id)->toBe($deployment->id)
        ->and($stop->payload)->toBe(['remove_images' => true]);
});

test('deleting a project whose container a stop already removed creates no command', function (): void {
    $node = idleSleepNode();
    $owner = User::factory()->create();
    [$project, $deployment] = idleSleepProject($node, owner: $owner);
    idleSleepCommand($project, $deployment, AgentCommandStatus::Succeeded, ['completed_at' => now()->subHour()]);
    AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::StopProject,
        'status' => AgentCommandStatus::Succeeded,
        'completed_at' => now()->subMinutes(10),
    ]);
    $project->update(['runtime_status' => RuntimeStatus::Stopped, 'active_deployment_id' => null]);

    $this->actingAs($owner, 'web')->deleteJson("/api/v1/app/projects/{$project->id}")->assertNoContent();

    expect(stopsOf($project)->where('status', AgentCommandStatus::Pending)->count())->toBe(0);
});

test('a sleeping project whose removal failed is asked again by the purge', function (): void {
    config(['sakala.project.deleted_retention_days' => 30]);
    $node = idleSleepNode();
    $owner = User::factory()->create();
    [$project, $deployment] = sleepingIdleProject($node, owner: $owner);

    $this->actingAs($owner, 'web')->deleteJson("/api/v1/app/projects/{$project->id}")->assertNoContent();
    stopsOf($project)->sole()->update(['status' => AgentCommandStatus::Failed, 'failed_at' => now()]);

    $this->travel(31)->days();
    $this->artisan('projects:purge-deleted')->assertSuccessful();

    $retry = stopsOf($project)->where('status', AgentCommandStatus::Pending)->sole();

    expect(Project::withTrashed()->find($project->id))->not->toBeNull()
        ->and($retry->deployment_id)->toBe($deployment->id)
        ->and($retry->payload)->toBe(['remove_images' => true]);
});

test('a suspended project with a kept container is not purged before the node removes it', function (): void {
    config(['sakala.project.deleted_retention_days' => 30]);
    [$project, $deployment, $owner] = suspendedKeptProject();

    $this->actingAs($owner, 'web')->deleteJson("/api/v1/app/projects/{$project->id}")->assertNoContent();
    stopsOf($project)->sole()->update(['status' => AgentCommandStatus::Expired]);

    $this->travel(31)->days();
    $this->artisan('projects:purge-deleted')->assertSuccessful();

    expect(Project::withTrashed()->find($project->id))->not->toBeNull()
        ->and(stopsOf($project)->where('status', AgentCommandStatus::Pending)->sole()->deployment_id)->toBe($deployment->id);
});

// ─── Quota ───────────────────────────────────────────────────────────────────

test('sleeping projects do not count as running containers', function (): void {
    $node = idleSleepNode();
    $owner = User::factory()->create();
    $team = teamWorkspace($owner, attributes: ['limit_profile_id' => LimitProfile::factory()->create(['max_active_containers' => 2])->id]);
    sleepingIdleProject($node, ['workspace_id' => $team->id], owner: $owner);
    sleepingIdleProject($node, ['workspace_id' => $team->id], owner: $owner);
    idleSleepProject($node, ['workspace_id' => $team->id], owner: $owner);

    $this->actingAs($owner, 'web')
        ->getJson("/api/v1/app/pilot-limits?workspace_id={$team->id}")
        ->assertOk()
        ->assertJsonPath('data.quotas.max_active_containers', 2)
        ->assertJsonPath('data.quotas.current_active_containers_count', 1);
});

test('redeploying a sleeping project takes a slot, like waking it', function (int $running, bool $allowed): void {
    $node = idleSleepNode();
    $owner = User::factory()->create();
    $team = teamWorkspace($owner, attributes: ['limit_profile_id' => LimitProfile::factory()->create([
        'max_active_containers' => 1,
        'max_active_deployments' => 5,
    ])->id]);
    [$project] = sleepingIdleProject($node, ['workspace_id' => $team->id], owner: $owner);

    for ($i = 0; $i < $running; $i++) {
        idleSleepProject($node, ['workspace_id' => $team->id], owner: $owner);
    }

    $check = fn () => app(PilotRuntimeLimitService::class)->checkActiveDeploymentLimit($owner, $project->fresh());

    if ($allowed) {
        $check();
        expect(true)->toBeTrue();
    } else {
        expect($check)->toThrow(ActiveContainerLimitExceededException::class);
    }

    // A serving project replaces its own container, so it never needs a slot.
    $project->update(['runtime_status' => RuntimeStatus::Running]);
    app(PilotRuntimeLimitService::class)->checkActiveDeploymentLimit($owner, $project->fresh());
})->with([
    'slot taken' => [1, false],
    'slot free' => [0, true],
]);

// ─── Admin views ─────────────────────────────────────────────────────────────

test('admins filter projects by the sleeping status', function (): void {
    $node = idleSleepNode();
    [$sleeping] = sleepingIdleProject($node);
    idleSleepProject($node);

    $this->actingAs(adminApiAdmin(), 'web')
        ->getJson('/api/v1/admin/projects?runtime_status=sleeping')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $sleeping->id);
});

test('the admin overview does not count sleeping projects as active', function (): void {
    $node = idleSleepNode();
    sleepingIdleProject($node);
    idleSleepProject($node);

    $this->actingAs(adminApiAdmin(), 'web')
        ->getJson('/api/v1/admin/overview?range=7d')
        ->assertOk()
        ->assertJsonPath('data.totals.active_projects', 1);
});

test('pilot limits expose the idle window and it is off by default', function (): void {
    $owner = User::factory()->create();

    $this->actingAs($owner, 'web')
        ->getJson('/api/v1/app/pilot-limits')
        ->assertOk()
        ->assertJsonPath('data.project_idle_sleep_minutes', 0);

    config(['sakala.project.idle_sleep_minutes' => 30]);

    $this->actingAs($owner, 'web')
        ->getJson('/api/v1/app/pilot-limits')
        ->assertJsonPath('data.project_idle_sleep_minutes', 30);
});

test('a sleeping project is kept as the current workload of its deployment', function (): void {
    $node = idleSleepNode();
    $owner = User::factory()->create();
    [$project, $deployment] = sleepingIdleProject($node, owner: $owner);
    Deployment::query()->whereKey($deployment->id)->update(['status' => DeploymentStatus::Succeeded]);

    $this->actingAs($owner, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}")
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});
