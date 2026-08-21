<?php

declare(strict_types=1);

use App\Events\Deployment\DeploymentEventCreated;
use App\Events\Deployment\DeploymentLogCreated;
use App\Events\Deployment\DeploymentUpdated;
use App\Models\Deployment;
use App\Models\DeploymentEvent;
use App\Models\DeploymentLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;

uses(RefreshDatabase::class);

test('deployment channel allows project owner', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
    ]);

    $channels = Broadcast::getChannels();

    expect($channels)->toHaveKey('deployment.{deploymentId}');

    $callback = $channels['deployment.{deploymentId}'];

    expect($callback($user, $deployment->id))->toBeTrue();
});

test('deployment channel rejects non project owner', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $owner->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
    ]);

    $channels = Broadcast::getChannels();

    $callback = $channels['deployment.{deploymentId}'];

    expect($callback($otherUser, $deployment->id))->toBeFalse();
});

test('deployment channel rejects missing deployment', function (): void {
    $user = User::factory()->create();

    $channels = Broadcast::getChannels();

    $callback = $channels['deployment.{deploymentId}'];

    expect($callback($user, '01a02191-384b-73aa-9f30-47cd4939b9b2'))->toBeFalse();
});

test('deployment events broadcast after transaction commit', function (): void {
    $deployment = Deployment::factory()->create();

    $events = [
        new DeploymentUpdated($deployment),
        new DeploymentEventCreated(
            DeploymentEvent::factory()->create([
                'deployment_id' => $deployment->id,
            ])
        ),
        new DeploymentLogCreated(
            DeploymentLog::factory()->create([
                'deployment_id' => $deployment->id,
            ])
        ),
    ];

    foreach ($events as $event) {
        expect($event->afterCommit)->toBeTrue();
    }
});

test('deployment updated broadcasts on deployment private channel', function (): void {
    $deployment = Deployment::factory()->create();

    $event = new DeploymentUpdated($deployment);

    expect($event->broadcastOn()[0]->name)
        ->toBe("private-deployment.{$deployment->id}");
});

test('deployment event created broadcasts on deployment private channel', function (): void {
    $deployment = Deployment::factory()->create();

    $deploymentEvent = DeploymentEvent::factory()->create([
        'deployment_id' => $deployment->id,
    ]);

    $event = new DeploymentEventCreated($deploymentEvent);

    expect($event->broadcastOn()[0]->name)
        ->toBe("private-deployment.{$deployment->id}");
});

test('deployment log created broadcasts on deployment private channel', function (): void {
    $deployment = Deployment::factory()->create();

    $deploymentLog = DeploymentLog::factory()->create([
        'deployment_id' => $deployment->id,
    ]);

    $event = new DeploymentLogCreated($deploymentLog);

    expect($event->broadcastOn()[0]->name)
        ->toBe("private-deployment.{$deployment->id}");
});
