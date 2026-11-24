<?php

declare(strict_types=1);

use App\Actions\Project\RequestProjectWakeAction;
use App\Enums\DeploymentBuildMode;
use App\Exceptions\Runtime\ActiveContainerLimitExceededException;
use App\Exceptions\Runtime\ProjectWakeLimitExceededException;
use App\Exceptions\Runtime\ProjectWorkerProcessesUnsupportedException;
use App\Exceptions\Runtime\WorkerContainerLimitExceededException;
use App\Models\AgentCommand;
use App\Models\Project;
use App\Services\Runtime\PilotRuntimeLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    enableWorkersAndSchedules();
});

test('DeployProject carries the processes in the agent contract shape, and the deployment counts its workers', function (): void {
    ['team' => $team, 'project' => $project, 'node' => $node] = workerProject(profile: ['max_replicas' => 2, 'max_worker_containers' => 3]);
    addProcess($project, ['name' => 'queue', 'command' => 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600', 'replicas' => 1, 'memory_mb' => 192, 'cpu_millis' => 250]);
    addProcess($project, ['name' => 'reports', 'command' => 'php artisan queue:work --queue=reports', 'replicas' => 0]);

    $deployment = rollbackDeploy($project, $team['owner'], WORKER_COMMIT);
    $payload = deployPayloadOf($deployment);
    $fixture = agentCommandFixture('deploy-project-processes');

    // Byte for byte the shape of sakala-agent examples/commands/deploy-project-processes.json.
    expect($payload['processes'])->toBe($fixture['payload']['processes'])
        ->and($deployment->worker_containers)->toBe(1)
        ->and($deployment->agent_node_id)->toBe($node->id);
});

test('a deployment without processes sends no processes key', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();

    $deployment = rollbackDeploy($project, $team['owner'], WORKER_COMMIT);

    expect(deployPayloadOf($deployment))->not->toHaveKey('processes')
        ->and($deployment->worker_containers)->toBe(0);
});

test('a deployment with workers is only placed on a node that advertises worker-processes', function (): void {
    $older = array_values(array_diff(WORKER_SCHEDULE_CAPABILITIES, ['worker-processes']));
    ['team' => $team, 'project' => $project] = workerProject(capabilities: $older);
    addProcess($project);

    expect(fn () => rollbackDeploy($project, $team['owner'], WORKER_COMMIT))
        ->toThrow(ProjectWorkerProcessesUnsupportedException::class)
        ->and(AgentCommand::query()->count())->toBe(0)
        ->and((new ProjectWorkerProcessesUnsupportedException)->render(request())->getStatusCode())->toBe(409);
});

test('a pending deployment with workers is only assigned to a capable node', function (): void {
    ['team' => $team, 'project' => $project, 'node' => $node] = workerProject();
    addProcess($project);
    $deployment = rollbackDeploy($project, $team['owner'], WORKER_COMMIT);
    $command = AgentCommand::query()->where('deployment_id', $deployment->id)->sole();

    expect($command->requestsWorkerProcesses())->toBeTrue()
        ->and($node->canRunDeployment(1, false, true))->toBeTrue()
        ->and($node->fill(['capabilities' => ['docker-runtime']])->canRunDeployment(1, false, true))->toBeFalse();
});

test('worker containers count against max_active_containers on deploy', function (): void {
    ['team' => $team, 'project' => $project] = workerProject(profile: ['max_active_containers' => 1, 'max_worker_containers' => 1]);
    addProcess($project);

    expect(fn () => rollbackDeploy($project, $team['member'], WORKER_COMMIT))
        ->toThrow(ActiveContainerLimitExceededException::class);
});

test('worker containers of the workspace stay within max_worker_containers across projects', function (): void {
    ['team' => $team, 'project' => $project] = workerProject(profile: ['max_active_containers' => 5, 'max_worker_containers' => 1]);
    addProcess($project);
    deployLive($project, $team['member']);

    $second = Project::factory()->inWorkspace($team['workspace'], $team['owner'])->create(['branch' => 'main', ...inspectedProjectAttributes(railpackStackInspection('express'))]);
    addProcess($second);

    expect(fn () => rollbackDeploy($second, $team['member'], WORKER_COMMIT))
        ->toThrow(WorkerContainerLimitExceededException::class);

    // A sleeping project holds no worker slot.
    sleepProject($project->fresh());
    expect(rollbackDeploy($second, $team['member'], WORKER_COMMIT)->worker_containers)->toBe(1);

    // Waking the first now would run two workers.
    expect(fn () => app(PilotRuntimeLimitService::class)->checkWakeLimit($project->fresh(), $project->fresh()->activeDeployment))
        ->toThrow(ProjectWakeLimitExceededException::class);
});

test('a wake counts the worker containers of the sleeping deployment', function (): void {
    ['team' => $team, 'project' => $project] = workerProject(profile: ['max_active_containers' => 2, 'max_worker_containers' => 2]);
    addProcess($project, ['replicas' => 1]);
    deployLive($project, $team['owner']);
    sleepProject($project->fresh());

    $other = Project::factory()->inWorkspace($team['workspace'], $team['owner'])->create(['branch' => 'main', ...inspectedProjectAttributes(railpackStackInspection('express'))]);
    deployLive($other, $team['owner']);

    // One container runs; the sleeping project needs two (web and worker).
    expect(fn () => app(RequestProjectWakeAction::class)->handle($project->fresh()))
        ->toThrow(ProjectWakeLimitExceededException::class);

    expect(app(PilotRuntimeLimitService::class)->getPilotQuotaLimits($team['workspace']->fresh()))
        ->current_active_containers_count->toBe(1)
        ->current_worker_containers_count->toBe(0);
});

test('a rollback runs the processes its target recorded', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();
    addProcess($project, ['name' => 'queue', 'command' => 'npm run worker:v1']);
    $old = deployLive($project, $team['owner'], ROLLBACK_OLD_COMMIT);

    $project->processes()->delete();
    $project->refresh()->refreshProcessesFingerprint();
    $project->save();
    deployLive($project, $team['owner'], ROLLBACK_NEW_COMMIT);

    $this->actingAs($team['owner'])->postJson(rollbackUrl($project->fresh(), $old))->assertCreated();

    $rollback = $project->fresh()->deployments()->orderByDesc('sequence')->first();

    expect(deployPayloadOf($rollback)['processes'][0])->toMatchArray(['name' => 'queue', 'command' => 'npm run worker:v1'])
        ->and($rollback->worker_containers)->toBe(1);
});

test('a redeploy that only changes processes reuses the live image', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();
    deployLive($project, $team['owner']);
    addProcess($project);

    expect($project->fresh()->requiresRedeploy())->toBeTrue();

    $redeploy = rollbackDeploy($project, $team['owner'], WORKER_COMMIT);

    expect($redeploy->build_mode)->toBe(DeploymentBuildMode::Reused)
        ->and(deployPayloadOf($redeploy))->toHaveKey('reuse_image_from_deployment_id')
        ->and(deployPayloadOf($redeploy)['processes'])->toHaveCount(1);
});
