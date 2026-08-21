<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Events\Deployment\DeploymentUpdated;
use App\Models\Deployment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('deployment updated broadcasts expected payload', function (): void {
    $deployment = Deployment::factory()->create([
        'status' => DeploymentStatus::Succeeded,
        'trigger' => DeploymentTrigger::Manual,
        'branch' => 'main',
        'commit_sha' => 'abc123',
        'commit_message' => 'fix: deploy application',
        'sequence' => 7,
    ]);

    $event = new DeploymentUpdated(
        [
            'deployment_id' => $deployment->id,
            'project_id' => $deployment->project_id,
            'sequence' => 8,
            'status' => 'succeeded',
            'trigger' => 'manual',
            'branch' => 'main',
            'commit_sha' => 'abc123',
            'commit_message' => 'fix: deploy application',
        ],
        $deployment->id,
    );

    expect($event->broadcastWith())
        ->toMatchArray([
            'deployment_id' => $deployment->id,
            'project_id' => $deployment->project_id,
            'sequence' => 8,
            'status' => 'succeeded',
            'trigger' => 'manual',
            'branch' => 'main',
            'commit_sha' => 'abc123',
            'commit_message' => 'fix: deploy application',
        ])
        ->not->toHaveKeys([
            'env',
            'environment',
            'token',
            'provider_token',
            'secret',
        ]);
});
