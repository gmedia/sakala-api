<?php

declare(strict_types=1);

use App\Enums\LogStream;
use App\Events\Deployment\DeploymentLogCreated;
use App\Models\Deployment;
use App\Models\DeploymentLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('deployment log created broadcasts expected payload', function (): void {
    $deployment = Deployment::factory()->create();

    $deploymentLog = DeploymentLog::factory()->create([
        'deployment_id' => $deployment->id,
        'sequence' => 4,
        'stream' => LogStream::Stdout,
        'message' => 'Building the application.',
    ]);

    $event = new DeploymentLogCreated($deploymentLog);

    expect($event->broadcastWith())
        ->toMatchArray([
            'deployment_id' => $deployment->id,
            'sequence' => 4,
            'stream' => 'stdout',
            'message' => 'Building the application.',
        ]);
});
