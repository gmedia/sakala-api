<?php

declare(strict_types=1);

use App\Actions\Deployment\PruneDeploymentLogsAction;
use App\Enums\DeploymentEventLevel;
use App\Enums\DeploymentStatus;
use App\Enums\LogStream;
use App\Models\Deployment;
use App\Models\DeploymentEvent;
use App\Models\DeploymentLog;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('prunes logs and events of terminal deployments older than retention threshold', function (): void {
    CarbonImmutable::setTestNow('2026-09-13 12:00:00');

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $oldTerminalDeployment = Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Succeeded,
        'created_at' => CarbonImmutable::now()->subDays(10),
    ]);

    DeploymentLog::factory()->count(3)->create([
        'deployment_id' => $oldTerminalDeployment->id,
        'stream' => LogStream::Stdout,
        'created_at' => CarbonImmutable::now()->subDays(10),
    ]);

    DeploymentEvent::factory()->count(2)->create([
        'deployment_id' => $oldTerminalDeployment->id,
        'level' => DeploymentEventLevel::Info,
        'created_at' => CarbonImmutable::now()->subDays(10),
    ]);

    $action = app(PruneDeploymentLogsAction::class);
    $result = $action->handle(days: 7, dryRun: false);

    expect($result->isDryRun)->toBeFalse()
        ->and($result->retentionDays)->toBe(7)
        ->and($result->affectedDeploymentsCount)->toBe(1)
        ->and($result->prunedLogsCount)->toBe(3)
        ->and($result->prunedEventsCount)->toBe(2);

    expect(DeploymentLog::query()->where('deployment_id', $oldTerminalDeployment->id)->count())->toBe(0)
        ->and(DeploymentEvent::query()->where('deployment_id', $oldTerminalDeployment->id)->count())->toBe(0);

    CarbonImmutable::setTestNow();
});

test('protects active deployments older than retention threshold', function (): void {
    CarbonImmutable::setTestNow('2026-09-13 12:00:00');

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $activeDeployment = Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Deploying,
        'created_at' => CarbonImmutable::now()->subDays(10),
    ]);

    DeploymentLog::factory()->count(3)->create([
        'deployment_id' => $activeDeployment->id,
        'created_at' => CarbonImmutable::now()->subDays(10),
    ]);

    DeploymentEvent::factory()->count(2)->create([
        'deployment_id' => $activeDeployment->id,
        'created_at' => CarbonImmutable::now()->subDays(10),
    ]);

    $action = app(PruneDeploymentLogsAction::class);
    $result = $action->handle(days: 7, dryRun: false);

    expect($result->affectedDeploymentsCount)->toBe(0)
        ->and($result->prunedLogsCount)->toBe(0)
        ->and($result->prunedEventsCount)->toBe(0);

    expect(DeploymentLog::query()->where('deployment_id', $activeDeployment->id)->count())->toBe(3)
        ->and(DeploymentEvent::query()->where('deployment_id', $activeDeployment->id)->count())->toBe(2);

    CarbonImmutable::setTestNow();
});

test('protects recent terminal deployments within the retention threshold', function (): void {
    CarbonImmutable::setTestNow('2026-09-13 12:00:00');

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $recentTerminalDeployment = Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Failed,
        'created_at' => CarbonImmutable::now()->subDays(3),
    ]);

    DeploymentLog::factory()->count(2)->create([
        'deployment_id' => $recentTerminalDeployment->id,
        'created_at' => CarbonImmutable::now()->subDays(3),
    ]);

    $action = app(PruneDeploymentLogsAction::class);
    $result = $action->handle(days: 7, dryRun: false);

    expect($result->affectedDeploymentsCount)->toBe(0)
        ->and($result->prunedLogsCount)->toBe(0);

    expect(DeploymentLog::query()->where('deployment_id', $recentTerminalDeployment->id)->count())->toBe(2);

    CarbonImmutable::setTestNow();
});

test('retention starts at terminal time instead of deployment creation time', function (): void {
    CarbonImmutable::setTestNow('2026-09-13 12:00:00');

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $deployment = Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Succeeded,
        'created_at' => CarbonImmutable::now()->subDays(30),
        'finished_at' => CarbonImmutable::now()->subDays(2),
    ]);

    DeploymentLog::factory()->create([
        'deployment_id' => $deployment->id,
        'created_at' => CarbonImmutable::now()->subDays(30),
    ]);

    DeploymentEvent::factory()->create([
        'deployment_id' => $deployment->id,
        'created_at' => CarbonImmutable::now()->subDays(30),
    ]);

    $action = app(PruneDeploymentLogsAction::class);
    $result = $action->handle(days: 7);

    expect($result->affectedDeploymentsCount)->toBe(0)
        ->and($result->prunedLogsCount)->toBe(0)
        ->and($result->prunedEventsCount)->toBe(0)
        ->and(DeploymentLog::query()->whereBelongsTo($deployment)->exists())->toBeTrue()
        ->and(DeploymentEvent::query()->whereBelongsTo($deployment)->exists())->toBeTrue();

    $deployment->update([
        'finished_at' => CarbonImmutable::now()->subDays(8),
    ]);

    $result = $action->handle(days: 7);

    expect($result->affectedDeploymentsCount)->toBe(1)
        ->and($result->prunedLogsCount)->toBe(1)
        ->and($result->prunedEventsCount)->toBe(1);

    CarbonImmutable::setTestNow();
});

test('dry run returns candidate count without deleting records', function (): void {
    CarbonImmutable::setTestNow('2026-09-13 12:00:00');

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $oldTerminalDeployment = Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Cancelled,
        'created_at' => CarbonImmutable::now()->subDays(15),
    ]);

    DeploymentLog::factory()->count(4)->create([
        'deployment_id' => $oldTerminalDeployment->id,
        'created_at' => CarbonImmutable::now()->subDays(15),
    ]);

    DeploymentEvent::factory()->count(1)->create([
        'deployment_id' => $oldTerminalDeployment->id,
        'created_at' => CarbonImmutable::now()->subDays(15),
    ]);

    $action = app(PruneDeploymentLogsAction::class);
    $result = $action->handle(days: 7, dryRun: true);

    expect($result->isDryRun)->toBeTrue()
        ->and($result->affectedDeploymentsCount)->toBe(1)
        ->and($result->prunedLogsCount)->toBe(4)
        ->and($result->prunedEventsCount)->toBe(1);

    expect(DeploymentLog::query()->where('deployment_id', $oldTerminalDeployment->id)->count())->toBe(4)
        ->and(DeploymentEvent::query()->where('deployment_id', $oldTerminalDeployment->id)->count())->toBe(1);

    CarbonImmutable::setTestNow();
});

test('throws InvalidArgumentException for invalid retention days', function (): void {
    $action = app(PruneDeploymentLogsAction::class);

    expect(fn () => $action->handle(days: 0))
        ->toThrow(InvalidArgumentException::class, 'Retention days must be at least 1.');
});

test('throws InvalidArgumentException for invalid batch size', function (): void {
    $action = app(PruneDeploymentLogsAction::class);

    expect(fn () => $action->handle(batchSize: 0))
        ->toThrow(InvalidArgumentException::class, 'Batch size must be at least 1.');
});

test('handles empty dataset gracefully', function (): void {
    $action = app(PruneDeploymentLogsAction::class);
    $result = $action->handle(days: 7, dryRun: false);

    expect($result->affectedDeploymentsCount)->toBe(0)
        ->and($result->prunedLogsCount)->toBe(0)
        ->and($result->prunedEventsCount)->toBe(0);
});
