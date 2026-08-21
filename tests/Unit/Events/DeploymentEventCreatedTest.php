<?php

declare(strict_types=1);

use App\Enums\DeploymentEventLevel;
use App\Events\Deployment\DeploymentEventCreated;
use App\Models\Deployment;
use App\Models\DeploymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('deployment event created broadcasts expected payload', function (): void {
    $deployment = Deployment::factory()->create();

    $deploymentEvent = DeploymentEvent::factory()->create([
        'deployment_id' => $deployment->id,
        'sequence' => 3,
        'level' => DeploymentEventLevel::Info,
        'type' => 'deployment.building',
        'message' => 'Building the application.',
    ]);

    $event = new DeploymentEventCreated($deploymentEvent);

    expect($event->broadcastWith())
        ->toMatchArray([
            'deployment_id' => $deployment->id,
            'sequence' => 3,
            'level' => 'info',
            'type' => 'deployment.building',
            'message' => 'Building the application.',
        ]);
});
