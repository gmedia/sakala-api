<?php

declare(strict_types=1);

use App\Actions\Deployment\TransitionDeploymentAction;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;

uses(RefreshDatabase::class);

test('queued deployment can transition to cloning', function (): void {
    $project = Project::factory()->create();

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Queued,
        'sequence' => 1,
    ]);

    $result = app(TransitionDeploymentAction::class)->handle(
        deployment: $deployment,
        nextStatus: DeploymentStatus::Cloning,
    );

    expect($result->status)
        ->toBe(DeploymentStatus::Cloning);

    expect($result->started_at)->not->toBeNull();
    expect($result->events()->first())->not->toBeNull();
    expect($result->logs()->first())->not->toBeNull();
});

test('invalid deployment transition is rejected', function (): void {
    $deployment = Deployment::factory()->create([
        'status' => DeploymentStatus::Queued,
    ]);

    expect(fn () => app(TransitionDeploymentAction::class)->handle(
        deployment: $deployment,
        nextStatus: DeploymentStatus::Succeeded,
    ))->toThrow(InvalidArgumentException::class);
});

test('terminal deployment cannot transition again', function (): void {
    $deployment = Deployment::factory()->create([
        'status' => DeploymentStatus::Succeeded,
    ]);

    expect(fn () => app(TransitionDeploymentAction::class)->handle(
        deployment: $deployment,
        nextStatus: DeploymentStatus::Building,
    ))->toThrow(InvalidArgumentException::class);
});

test('failed transition is allowed from a non terminal state', function (): void {
    $deployment = Deployment::factory()->create([
        'status' => DeploymentStatus::Building,
    ]);

    $result = app(TransitionDeploymentAction::class)->handle(
        deployment: $deployment,
        nextStatus: DeploymentStatus::Failed,
    );

    expect($result->status)
        ->toBe(DeploymentStatus::Failed);

    expect($result->finished_at)->not->toBeNull();
});

test('succeeded transition updates project runtime', function (): void {
    $project = Project::factory()->create([
        'status' => ProjectStatus::Draft,
        'runtime_status' => RuntimeStatus::NotDeployed,
        'last_deployed_at' => null,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::HealthChecking,
    ]);

    app(TransitionDeploymentAction::class)->handle(
        deployment: $deployment,
        nextStatus: DeploymentStatus::Succeeded,
    );

    $project->refresh();

    expect($project->status)
        ->toBe(ProjectStatus::Active);

    expect($project->runtime_status)
        ->toBe(RuntimeStatus::Running);

    expect($project->last_deployed_at)
        ->not->toBeNull();
});

test('failed transition updates project runtime', function (): void {
    $project = Project::factory()->create([
        'runtime_status' => RuntimeStatus::NotDeployed,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Building,
    ]);

    app(TransitionDeploymentAction::class)->handle(
        deployment: $deployment,
        nextStatus: DeploymentStatus::Failed,
    );

    $project->refresh();

    expect($project->runtime_status)
        ->toBe(RuntimeStatus::Failed);
});

test('health checking deployment can be cancelled', function (): void {
    $deployment = Deployment::factory()->create([
        'status' => DeploymentStatus::HealthChecking,
        'cancelled_at' => null,
        'finished_at' => null,
    ]);

    $result = app(TransitionDeploymentAction::class)->handle(
        deployment: $deployment,
        nextStatus: DeploymentStatus::Cancelled,
    );

    expect($result->status)
        ->toBe(DeploymentStatus::Cancelled);

    expect($result->cancelled_at)
        ->not->toBeNull();

    expect($result->finished_at)
        ->not->toBeNull();
});
