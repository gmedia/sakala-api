<?php

declare(strict_types=1);

use App\Actions\Agent\ExpireAgentCommandsAction;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function truthNode(string $token = 'truth-token'): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
    ]);
}

function truthHeaders(AgentNode $node, string $token = 'truth-token'): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'X-Agent-Id' => $node->agent_id,
    ];
}

/** @return array{Deployment, AgentCommand} */
function runningAttempt(Project $project, AgentNode $node, int $sequence): array
{
    $deployment = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Building,
        'sequence' => $sequence,
    ]);

    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Running,
    ]);

    return [$deployment, $command];
}

test('a successful deployment becomes the active deployment', function (): void {
    $node = truthNode();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    [$deployment, $command] = runningAttempt($project, $node, 1);

    $this->withHeaders(truthHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.active_deployment_id', $deployment->id)
        ->assertJsonPath('data.runtime_status', 'running');

    $this->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}")
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});

test('a failed redeploy keeps the previous deployment serving', function (): void {
    $node = truthNode();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active]);
    [$first, $firstCommand] = runningAttempt($project, $node, 1);

    $this->withHeaders(truthHeaders($node))
        ->postJson("/api/agent/v1/commands/{$firstCommand->id}/complete", ['result' => null])
        ->assertNoContent();

    [$second, $secondCommand] = runningAttempt($project, $node, 2);

    $this->withHeaders(truthHeaders($node))
        ->postJson("/api/agent/v1/commands/{$secondCommand->id}/fail", [
            'error_code' => 'runtime_app_not_listening',
            'error_message' => 'Aplikasi tidak listen di port 3000.',
        ])
        ->assertNoContent();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($project->fresh()->active_deployment_id)->toBe($first->id);

    $this->actingAs($user, 'web');

    $this->getJson("/api/v1/app/projects/{$project->id}/deployments/{$first->id}")
        ->assertJsonPath('data.is_active', true);
    $this->getJson("/api/v1/app/projects/{$project->id}/deployments/{$second->id}")
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.status', 'failed');
});

test('a redeploy closed by lease expiry also keeps the previous deployment serving', function (): void {
    $node = truthNode();
    $project = Project::factory()->create();
    $live = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
    ]);
    $project->update(['active_deployment_id' => $live->id, 'runtime_status' => RuntimeStatus::Deploying]);
    [, $command] = runningAttempt($project, $node, 2);
    $command->update(['lease_expires_at' => now()->subMinute()]);

    app(ExpireAgentCommandsAction::class)->handle();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($project->fresh()->active_deployment_id)->toBe($live->id);
});

test('a failed first deployment leaves the project failed with nothing active', function (): void {
    $node = truthNode();
    $project = Project::factory()->create();
    [, $command] = runningAttempt($project, $node, 1);

    $this->withHeaders(truthHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", [
            'error_code' => 'runtime_build_failed',
            'error_message' => 'build failed',
        ])
        ->assertNoContent();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Failed)
        ->and($project->fresh()->active_deployment_id)->toBeNull();
});

test('a known crash of the live workload survives a failed redeploy', function (): void {
    $node = truthNode();
    $project = Project::factory()->create(['runtime_status' => RuntimeStatus::Crashed]);
    $live = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
    ]);
    $project->update(['active_deployment_id' => $live->id]);
    [$attempt, $command] = runningAttempt($project, $node, 2);
    $attempt->update(['status' => DeploymentStatus::Queued]);

    $this->withHeaders(truthHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", [
            'error_code' => 'runtime_build_failed',
            'error_message' => 'build failed',
        ])
        ->assertNoContent();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Crashed);
});

test('a newer successful deployment supersedes the old one', function (): void {
    $node = truthNode();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    [$first, $firstCommand] = runningAttempt($project, $node, 1);

    $this->withHeaders(truthHeaders($node))
        ->postJson("/api/agent/v1/commands/{$firstCommand->id}/complete", ['result' => null])
        ->assertNoContent();

    [$second, $secondCommand] = runningAttempt($project, $node, 2);

    $this->withHeaders(truthHeaders($node))
        ->postJson("/api/agent/v1/commands/{$secondCommand->id}/complete", ['result' => null])
        ->assertNoContent();

    expect($project->fresh()->active_deployment_id)->toBe($second->id);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$first->id}")
        ->assertJsonPath('data.is_active', false);
});

test('a late completion of an older attempt does not move the active deployment back', function (): void {
    $node = truthNode();
    $project = Project::factory()->create();
    [$older, $olderCommand] = runningAttempt($project, $node, 1);
    [$newer, $newerCommand] = runningAttempt($project, $node, 2);

    foreach ([$newerCommand, $olderCommand] as $command) {
        $this->withHeaders(truthHeaders($node))
            ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
            ->assertNoContent();
    }

    expect($project->fresh()->active_deployment_id)->toBe($newer->id)
        ->and($older->fresh()->status)->toBe(DeploymentStatus::Succeeded);
});

test('stopping the current workload clears the active deployment', function (AgentCommandType $type): void {
    $node = truthNode();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['runtime_status' => RuntimeStatus::Running]);
    $live = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
    ]);
    $project->update(['active_deployment_id' => $live->id]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $live->id,
        'agent_node_id' => $node->id,
        'type' => $type,
        'status' => AgentCommandStatus::Running,
    ]);

    $this->withHeaders(truthHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Stopped)
        ->and($project->fresh()->active_deployment_id)->toBeNull();

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$live->id}")
        ->assertJsonPath('data.is_active', false);
})->with([AgentCommandType::StopProject, AgentCommandType::SleepProject]);

test('a failed stop leaves the active deployment in place', function (): void {
    $node = truthNode();
    $project = Project::factory()->create(['runtime_status' => RuntimeStatus::Running]);
    $live = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
    ]);
    $project->update(['active_deployment_id' => $live->id]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $live->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::StopProject,
        'status' => AgentCommandStatus::Running,
    ]);

    $this->withHeaders(truthHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", [
            'error_code' => 'runtime_container_failed',
            'error_message' => 'docker stop failed',
        ])
        ->assertNoContent();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($project->fresh()->active_deployment_id)->toBe($live->id);
});

test('a stale stop of a superseded workload keeps the active deployment', function (): void {
    $node = truthNode();
    $project = Project::factory()->create(['runtime_status' => RuntimeStatus::Running]);
    $old = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
    ]);
    $live = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 2,
    ]);
    $project->update(['active_deployment_id' => $live->id]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $old->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::StopProject,
        'status' => AgentCommandStatus::Running,
    ]);

    $this->withHeaders(truthHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();

    expect($project->fresh()->active_deployment_id)->toBe($live->id)
        ->and($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running);
});

test('a deployment that finishes after suspend is active until its sleep completes', function (): void {
    $node = truthNode();
    $project = Project::factory()->create(['status' => ProjectStatus::Suspended]);
    [$deployment, $command] = runningAttempt($project, $node, 1);

    $this->withHeaders(truthHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();

    expect($project->fresh()->active_deployment_id)->toBe($deployment->id)
        ->and($project->fresh()->status)->toBe(ProjectStatus::Suspended);
});

test('effective_port falls back to the deploy default and follows detected_port', function (?int $detected, int $expected): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['detected_port' => $detected]);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.detected_port', $detected)
        ->assertJsonPath('data.effective_port', $expected);
})->with([
    'not detected' => [null, 3000],
    'detected' => [8080, 8080],
]);

test('the port sent to the agent is the effective_port the console shows', function (?int $detected): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => '0123456789abcdef0123456789abcdef01234567', 'commit' => ['message' => 'feat: initial']],
        ], 200),
    ]);

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['branch' => 'main', 'detected_port' => $detected]);

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertCreated();

    $command = AgentCommand::query()->where('project_id', $project->id)->sole();
    $effectivePort = $this->getJson("/api/v1/app/projects/{$project->id}")->json('data.effective_port');

    expect($command->payload['container_port'])->toBe($effectivePort);
})->with([null, 8080]);

test('another user cannot read the runtime fields of a project', function (): void {
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create(), 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertForbidden();
});

test('listing deployments marks only the active one without extra project queries', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $deployments = Deployment::factory()->for($project)
        ->count(5)
        ->sequence(fn ($sequence): array => ['sequence' => $sequence->index + 1, 'status' => DeploymentStatus::Succeeded])
        ->create();
    $project->update(['active_deployment_id' => $deployments->last()->id]);

    $this->actingAs($user, 'web');

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->getJson("/api/v1/app/projects/{$project->id}/deployments")->assertOk();

    $flags = collect($response->json('data'))->mapWithKeys(
        static fn (array $item): array => [$item['id'] => $item['is_active']],
    );

    expect($flags->filter()->keys()->all())->toBe([$deployments->last()->id])
        ->and($flags)->toHaveCount(5);

    $projectLookups = array_filter(
        $queries,
        static fn (string $sql): bool => str_contains($sql, 'from "projects"'),
    );

    // Route binding plus one eager load, whatever the page size.
    expect(count($projectLookups))->toBeLessThanOrEqual(2);
});

test('the backfill restores the active deployment and undoes the failed-redeploy lie', function (): void {
    $node = truthNode();
    $migration = require database_path('migrations/2026_11_05_143110_add_active_deployment_id_to_projects_table.php');

    $failedRedeploy = Project::factory()->create(['runtime_status' => RuntimeStatus::Failed]);
    $served = Deployment::factory()->for($failedRedeploy)->create([
        'agent_node_id' => $node->id, 'status' => DeploymentStatus::Succeeded, 'sequence' => 1,
    ]);
    Deployment::factory()->for($failedRedeploy)->create([
        'agent_node_id' => $node->id, 'status' => DeploymentStatus::Failed, 'sequence' => 2,
    ]);

    $neverDeployed = Project::factory()->create(['runtime_status' => RuntimeStatus::Failed]);
    Deployment::factory()->for($neverDeployed)->create([
        'agent_node_id' => $node->id, 'status' => DeploymentStatus::Failed, 'sequence' => 1,
    ]);

    $stopped = Project::factory()->create(['runtime_status' => RuntimeStatus::Stopped]);
    Deployment::factory()->for($stopped)->create([
        'agent_node_id' => $node->id, 'status' => DeploymentStatus::Succeeded, 'sequence' => 1,
    ]);

    $stoppedThenFailed = Project::factory()->create(['runtime_status' => RuntimeStatus::Failed]);
    $stoppedDeployment = Deployment::factory()->for($stoppedThenFailed)->create([
        'agent_node_id' => $node->id, 'status' => DeploymentStatus::Succeeded, 'sequence' => 1,
    ]);
    AgentCommand::factory()->create([
        'project_id' => $stoppedThenFailed->id,
        'deployment_id' => $stoppedDeployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::StopProject,
        'status' => AgentCommandStatus::Succeeded,
    ]);

    $running = Project::factory()->create(['runtime_status' => RuntimeStatus::Running]);
    $live = Deployment::factory()->for($running)->create([
        'agent_node_id' => $node->id, 'status' => DeploymentStatus::Succeeded, 'sequence' => 1,
    ]);

    // SQLite cannot drop this column while other tables reference `projects`,
    // and a fresh column is what the backfill sees, so run the backfill alone.
    (fn () => $this->backfillActiveDeployments())->call($migration);

    expect($failedRedeploy->fresh()->active_deployment_id)->toBe($served->id)
        ->and($failedRedeploy->fresh()->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($neverDeployed->fresh()->active_deployment_id)->toBeNull()
        ->and($neverDeployed->fresh()->runtime_status)->toBe(RuntimeStatus::Failed)
        ->and($stopped->fresh()->active_deployment_id)->toBeNull()
        ->and($stopped->fresh()->runtime_status)->toBe(RuntimeStatus::Stopped)
        ->and($stoppedThenFailed->fresh()->active_deployment_id)->toBeNull()
        ->and($stoppedThenFailed->fresh()->runtime_status)->toBe(RuntimeStatus::Failed)
        ->and($running->fresh()->active_deployment_id)->toBe($live->id)
        ->and($running->fresh()->runtime_status)->toBe(RuntimeStatus::Running);
});

test('the OpenAPI document describes the new fields with their real types', function (): void {
    config()->set('scramble.enabled', true);

    $schemas = $this->getJson('/docs/api.json')->assertOk()->json('components.schemas');

    expect($schemas['ProjectResource']['properties']['active_deployment_id']['type'])->toBe(['string', 'null'])
        ->and($schemas['ProjectResource']['properties']['effective_port']['type'])->toBe('integer')
        ->and($schemas['DeploymentResource']['properties']['is_active']['type'])->toBe('boolean');

    $failure = $schemas['DeploymentResource']['properties']['failure']['properties'];

    expect($failure['user_fixable']['type'])->toBe('boolean')
        ->and($failure['detail']['type'])->toBe(['string', 'null'])
        ->and($failure['stage']['type'])->toBe(['string', 'null'])
        ->and($failure['stage']['enum'])->toContain('queued', 'health_checking', null);

    expect($schemas['DeploymentResource']['properties']['applied_resources']['properties'])->toHaveKeys(['memory_mb', 'cpu_millis', 'pids_limit']);

    expect($schemas['DeploymentEventResource']['properties']['metadata']['type'])->toBe(['object', 'null'])
        ->and($schemas['ProjectResource']['properties']['serving_port']['type'])->toBe(['integer', 'null']);

    expect($schemas['ProjectResource']['properties']['configured_port']['type'])->toBe(['integer', 'null'])
        ->and($schemas['ProjectResource']['properties']['redeploy_required']['type'])->toBe('boolean')
        ->and($schemas['ProjectResource']['properties']['stack']['properties']['builder']['enum'])->toBe(['dockerfile', 'railpack'])
        ->and($schemas['DeploymentResource']['properties']['queue_state']['enum'])->toContain('assigned', 'waiting_for_node', null)
        ->and($schemas['DeploymentResource']['properties']['cancel_requested_at']['type'])->toBe(['string', 'null'])
        ->and($schemas['UserResource']['properties']['has_password']['type'])->toBe('boolean')
        ->and($schemas['EnvironmentVariableResource']['properties']['is_secret']['type'])->toBe('boolean')
        ->and($schemas['NotificationPreferenceResource']['properties']['project_issue']['type'])->toBe('boolean');
});

test('serving_port stays on the deployed port until a new deployment replaces it', function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => '0123456789abcdef0123456789abcdef01234567', 'commit' => ['message' => 'feat: initial']],
        ], 200),
    ]);

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['branch' => 'main', 'configured_port' => 8080]);

    $this->actingAs($user, 'web');

    $id = $this->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])->assertCreated()->json('data.id');
    $deployment = Deployment::query()->findOrFail($id);

    expect($deployment->container_port)->toBe(8080)
        ->and(AgentCommand::query()->where('deployment_id', $id)->sole()->payload['container_port'])->toBe(8080);

    $this->getJson("/api/v1/app/projects/{$project->id}")
        ->assertJsonPath('data.serving_port', null)
        ->assertJsonPath('data.effective_port', 8080);

    $deployment->update(['status' => DeploymentStatus::Succeeded]);
    $project->update(['active_deployment_id' => $id, 'runtime_status' => RuntimeStatus::Running]);

    $this->patchJson("/api/v1/app/projects/{$project->id}", ['configured_port' => 9000])
        ->assertOk()
        ->assertJsonPath('data.effective_port', 9000)
        ->assertJsonPath('data.serving_port', 8080);
});

test('nothing serving means no serving_port', function (RuntimeStatus $status, bool $active): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['runtime_status' => $status]);
    $deployment = Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
        'container_port' => 3000,
    ]);
    $project->update(['active_deployment_id' => $active ? $deployment->id : null]);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertJsonPath('data.serving_port', null);
})->with([
    'crashed' => [RuntimeStatus::Crashed, true],
    'stopped' => [RuntimeStatus::Stopped, false],
    'never deployed' => [RuntimeStatus::NotDeployed, false],
]);

test('the backfill copies the port from each deploy command', function (): void {
    $migration = require database_path('migrations/2026_11_07_000001_add_container_port_to_deployments_table.php');
    $project = Project::factory()->create();
    $withCommand = Deployment::factory()->for($project)->create(['sequence' => 1]);
    $withoutCommand = Deployment::factory()->for($project)->create(['sequence' => 2]);
    AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $withCommand->id,
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Succeeded,
        'payload' => ['container_port' => 8081],
    ]);

    (fn () => $this->backfillFromCommands())->call($migration);

    expect($withCommand->fresh()->container_port)->toBe(8081)
        ->and($withoutCommand->fresh()->container_port)->toBeNull();
});

test('an empty map on a deployment never serialises as a JSON list', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($project)->create([
        'sequence' => 1,
        'requested_resources' => [],
        'effective_resources' => [],
        'applied_resources' => [],
    ]);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}")
        ->assertOk()
        ->assertJsonPath('data.requested_resources', null)
        ->assertJsonPath('data.effective_resources', null)
        ->assertJsonPath('data.applied_resources', null);
});
