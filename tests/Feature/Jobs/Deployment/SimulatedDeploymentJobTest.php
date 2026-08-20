<?php

declare(strict_types=1);

use App\Actions\Deployment\TransitionDeploymentAction;
use App\Enums\DeploymentStatus;
use App\Jobs\Deployment\SimulatedDeploymentJob;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('simulated deployment job completes deployment successfully', function (): void {
    $project = Project::factory()->create();

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Queued,
        'sequence' => 1,
        'commit_sha' => 'abc123456789',
    ]);

    $job = new SimulatedDeploymentJob($deployment);

    $job->handle(
        app(TransitionDeploymentAction::class),
    );

    $deployment->refresh();

    expect($deployment->status)
        ->toBe(DeploymentStatus::Succeeded);

    expect($deployment->started_at)
        ->not->toBeNull();

    expect($deployment->finished_at)
        ->not->toBeNull();

    expect($deployment->events()->count())
        ->toBe(7);

    expect($deployment->logs()->count())
        ->toBe(7);
});

test('simulated deployment job fails deployment deterministically', function (): void {
    $project = Project::factory()->create();

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Queued,
        'sequence' => 1,
        'commit_sha' => 'abc1234567a0',
    ]);

    $job = new SimulatedDeploymentJob($deployment);

    $job->handle(
        app(TransitionDeploymentAction::class),
    );

    $deployment->refresh();

    expect($deployment->status)
        ->toBe(DeploymentStatus::Failed);

    expect($deployment->started_at)
        ->not->toBeNull();

    expect($deployment->finished_at)
        ->not->toBeNull();
});

test('failed job transitions non terminal deployment to failed', function (): void {
    $project = Project::factory()->create();

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Building,
        'sequence' => 1,
    ]);

    $job = new SimulatedDeploymentJob($deployment);

    $job->failed(
        new RuntimeException('Simulated worker failure'),
    );

    $deployment->refresh();

    expect($deployment->status)
        ->toBe(DeploymentStatus::Failed);

    expect($deployment->finished_at)
        ->not->toBeNull();
});

test('failed job does not transition terminal deployment', function (): void {
    $project = Project::factory()->create();

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
    ]);

    $job = new SimulatedDeploymentJob($deployment);

    $job->failed(
        new RuntimeException('Simulated worker failure'),
    );

    $deployment->refresh();

    expect($deployment->status)
        ->toBe(DeploymentStatus::Succeeded);
});

test('failed job does not transition cancelled deployment', function (): void {
    $project = Project::factory()->create();

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Cancelled,
        'sequence' => 1,
    ]);

    $job = new SimulatedDeploymentJob($deployment);

    $job->failed(
        new RuntimeException('Simulated worker failure'),
    );

    $deployment->refresh();

    expect($deployment->status)
        ->toBe(DeploymentStatus::Cancelled);
});

test('simulated deployment job ignores succeeded deployment', function (): void {
    $project = Project::factory()->create();

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
    ]);

    $job = new SimulatedDeploymentJob($deployment);

    $job->handle(
        app(TransitionDeploymentAction::class),
    );

    expect($deployment->fresh()->status)
        ->toBe(DeploymentStatus::Succeeded);
});

test('simulated deployment job ignores failed deployment', function (): void {
    $project = Project::factory()->create();

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Failed,
        'sequence' => 1,
    ]);

    $job = new SimulatedDeploymentJob($deployment);

    $job->handle(
        app(TransitionDeploymentAction::class),
    );

    expect($deployment->fresh()->status)
        ->toBe(DeploymentStatus::Failed);
});

test('simulated deployment job ignores cancelled deployment', function (): void {
    $project = Project::factory()->create();

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Cancelled,
        'sequence' => 1,
    ]);

    $job = new SimulatedDeploymentJob($deployment);

    $job->handle(
        app(TransitionDeploymentAction::class),
    );

    expect($deployment->fresh()->status)
        ->toBe(DeploymentStatus::Cancelled);
});
