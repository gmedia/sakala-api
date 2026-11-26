<?php

declare(strict_types=1);

use App\Actions\Schedule\DispatchSchedulesAction;
use App\Enums\AgentCommandType;
use App\Models\AgentCommand;
use App\Models\ScheduleRun;
use App\Support\Runtime\RuntimeFeatures;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('both features are off in code unless an operator turns them on', function (): void {
    expect(RuntimeFeatures::workersEnabled())->toBeFalse()
        ->and(RuntimeFeatures::schedulesEnabled())->toBeFalse();
});

test('with the flags off, the endpoints do not exist', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();
    $process = addProcess($project);
    $schedule = scheduleFor($project);

    $this->actingAs($team['owner'])->getJson("/api/v1/app/projects/{$project->id}/processes")->assertNotFound();
    $this->actingAs($team['owner'])->postJson("/api/v1/app/projects/{$project->id}/processes", ['name' => 'mailer', 'command' => 'x'])->assertNotFound();
    $this->actingAs($team['owner'])->deleteJson("/api/v1/app/projects/{$project->id}/processes/{$process->id}")->assertNotFound();
    $this->actingAs($team['owner'])->getJson("/api/v1/app/projects/{$project->id}/schedules")->assertNotFound();
    $this->actingAs($team['owner'])->postJson("/api/v1/app/projects/{$project->id}/schedules/{$schedule->id}/runs")->assertNotFound();
});

test('with the flags off, stored definitions change nothing: no processes key, no worker containers, no redeploy hint', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();
    $live = deployLive($project, $team['owner']);
    $fingerprint = $project->fresh()->deploymentConfigFingerprint();

    addProcess($project);

    expect($project->fresh()->deploymentConfigFingerprint())->toBe($fingerprint)
        ->and($project->fresh()->requiresRedeploy())->toBeFalse();

    $next = rollbackDeploy($project, $team['owner'], WORKER_COMMIT);

    expect(deployPayloadOf($next))->not->toHaveKey('processes')
        // The same keys as before, apart from reusing the live image of the same commit.
        ->and(array_values(array_diff(array_keys(deployPayloadOf($next)), ['reuse_image_from_deployment_id'])))->toBe(array_keys(deployPayloadOf($live)))
        ->and($next->worker_containers)->toBe(0);
});

test('with the flags off, schedules:dispatch records and sends nothing', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();
    deployLive($project, $team['owner']);
    scheduleFor($project, ['next_run_at' => CarbonImmutable::now()->subMinute()]);

    expect(app(DispatchSchedulesAction::class)->handle())->toBe(['started' => 0, 'waiting' => 0, 'skipped' => 0, 'missed' => 0])
        ->and(ScheduleRun::query()->count())->toBe(0)
        ->and(AgentCommand::query()->where('type', AgentCommandType::RunJob)->count())->toBe(0);
});
