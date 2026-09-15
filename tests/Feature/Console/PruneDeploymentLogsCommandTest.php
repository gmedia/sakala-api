<?php

declare(strict_types=1);

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

test('it runs dry run and reports eligible records without pruning', function (): void {
    CarbonImmutable::setTestNow('2026-09-13 12:00:00');

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $terminalDeployment = Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Succeeded,
        'created_at' => CarbonImmutable::now()->subDays(10),
    ]);

    DeploymentLog::factory()->count(3)->create([
        'deployment_id' => $terminalDeployment->id,
        'stream' => LogStream::Stdout,
        'created_at' => CarbonImmutable::now()->subDays(10),
    ]);

    DeploymentEvent::factory()->count(2)->create([
        'deployment_id' => $terminalDeployment->id,
        'level' => DeploymentEventLevel::Info,
        'created_at' => CarbonImmutable::now()->subDays(10),
    ]);

    $this->artisan('pilot:prune-logs', ['--dry-run' => true])
        ->expectsOutputToContain('[DRY RUN]')
        ->expectsOutputToContain('Deployment Logs Eligible')
        ->assertSuccessful();

    expect(DeploymentLog::query()->where('deployment_id', $terminalDeployment->id)->count())->toBe(3)
        ->and(DeploymentEvent::query()->where('deployment_id', $terminalDeployment->id)->count())->toBe(2);

    CarbonImmutable::setTestNow();
});

test('it executes pruning with custom days option', function (): void {
    CarbonImmutable::setTestNow('2026-09-13 12:00:00');

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $oldDeployment = Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Failed,
        'created_at' => CarbonImmutable::now()->subDays(10),
    ]);

    DeploymentLog::factory()->count(2)->create([
        'deployment_id' => $oldDeployment->id,
        'created_at' => CarbonImmutable::now()->subDays(10),
    ]);

    $recentDeployment = Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Failed,
        'created_at' => CarbonImmutable::now()->subDays(3),
    ]);

    DeploymentLog::factory()->count(2)->create([
        'deployment_id' => $recentDeployment->id,
        'created_at' => CarbonImmutable::now()->subDays(3),
    ]);

    $this->artisan('pilot:prune-logs', ['--days' => 5])
        ->expectsOutputToContain('Prune completed for deployments older than 5 days')
        ->expectsOutputToContain('Deployment Logs Pruned')
        ->assertSuccessful();

    expect(DeploymentLog::query()->where('deployment_id', $oldDeployment->id)->count())->toBe(0)
        ->and(DeploymentLog::query()->where('deployment_id', $recentDeployment->id)->count())->toBe(2);

    CarbonImmutable::setTestNow();
});

test('it fails when invalid days option is supplied', function (): void {
    $this->artisan('pilot:prune-logs', ['--days' => 0])
        ->expectsOutputToContain('Retention days must be at least 1.')
        ->assertFailed();
});
