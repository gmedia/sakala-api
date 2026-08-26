<?php

declare(strict_types=1);

use App\Enums\LogStream;
use App\Events\Deployment\DeploymentLogCreated;
use App\Models\Deployment;
use App\Models\DeploymentLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('deployment log created broadcasts expected payload', function (): void {
    $deployment = Deployment::factory()->create([
        'sequence' => 15,
        'realtime_sequence' => 7,
    ]);

    $deploymentLog = DeploymentLog::factory()->create([
        'deployment_id' => $deployment->id,
        'sequence' => 4,
        'stream' => LogStream::Stdout,
        'message' => 'Building the application.',
    ]);

    $event = new DeploymentLogCreated($deploymentLog, 8);

    expect($event->broadcastWith())
        ->toMatchArray([
            'deployment_id' => $deployment->id,
            'sequence' => 8,
            'stream' => 'stdout',
            'message' => 'Building the application.',
        ])
        ->and($event->broadcastWith()['sequence'])
        ->not->toBe($deploymentLog->sequence)
        ->not->toBe($deployment->sequence);
});

test('deployment log created does not expose sensitive values', function (): void {
    $deployment = Deployment::factory()->create();

    $deploymentLog = DeploymentLog::factory()->create([
        'deployment_id' => $deployment->id,
        'message' => 'Building the application.',
        'stream' => LogStream::Stdout,
        'message' => 'Building the application.',
    ]);

    $event = new DeploymentLogCreated($deploymentLog, 8);

    expect($event->broadcastWith())
        ->toMatchArray([
            'deployment_id' => $deployment->id,
            'sequence' => 8,
            'stream' => 'stdout',
            'message' => 'Building the application.',
        ])
        ->and($event->broadcastWith())
        ->not->toHaveKeys([
            'env',
            'environment',
            'token',
            'provider_token',
            'secret',
            'password',
            'credentials',
        ]);
});
