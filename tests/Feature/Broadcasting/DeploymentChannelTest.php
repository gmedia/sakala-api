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
        new DeploymentUpdated(
            [
                'deployment_id' => $deployment->id,
                'project_id' => $deployment->project_id,
                'sequence' => 1,
                'status' => $deployment->status->value,
                'trigger' => $deployment->trigger->value,
                'branch' => $deployment->branch,
                'commit_sha' => $deployment->commit_sha,
                'commit_message' => $deployment->commit_message,
            ],
            $deployment->id,
        ),
        new DeploymentEventCreated(
            DeploymentEvent::factory()->create([
                'deployment_id' => $deployment->id,
            ]),
            2,
        ),
        new DeploymentLogCreated(
            DeploymentLog::factory()->create([
                'deployment_id' => $deployment->id,
            ]),
            3,
        ),
    ];

    foreach ($events as $event) {
        expect($event->afterCommit)->toBeTrue();
    }
});

test('deployment updated broadcasts on deployment private channel', function (): void {
    $deployment = Deployment::factory()->create();

    $event = new DeploymentUpdated(
        [
            'deployment_id' => $deployment->id,
            'project_id' => $deployment->project_id,
            'sequence' => 1,
            'status' => $deployment->status->value,
            'trigger' => $deployment->trigger->value,
            'branch' => $deployment->branch,
            'commit_sha' => $deployment->commit_sha,
            'commit_message' => $deployment->commit_message,
        ],
        $deployment->id,
    );

    expect($event->broadcastOn()[0]->name)
        ->toBe("private-deployment.{$deployment->id}");
});

test('deployment event created broadcasts on deployment private channel', function (): void {
    $deployment = Deployment::factory()->create();

    $deploymentEvent = DeploymentEvent::factory()->create([
        'deployment_id' => $deployment->id,
    ]);

    $event = new DeploymentEventCreated(
        $deploymentEvent,
        1,
    );

    expect($event->broadcastOn()[0]->name)
        ->toBe("private-deployment.{$deployment->id}");
});

test('deployment log created broadcasts on deployment private channel', function (): void {
    $deployment = Deployment::factory()->create();

    $deploymentLog = DeploymentLog::factory()->create([
        'deployment_id' => $deployment->id,
    ]);

    $event = new DeploymentLogCreated(
        $deploymentLog,
        1,
    );

    expect($event->broadcastOn()[0]->name)
        ->toBe("private-deployment.{$deployment->id}");
});
