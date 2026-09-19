<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentEventLevel;
use App\Enums\LogStream;
use App\Enums\RepositoryAccess;

test('agent command types match the shared Rust protocol', function () {
    expect(array_column(AgentCommandType::cases(), 'value'))->toBe([
        'InspectProject',
        'DeployProject',
        'RestartProject',
        'StopProject',
        'SleepProject',
        'WakeProject',
        'HealthCheck',
        'RefreshRoute',
        'ReconcileWorkload',
        'CleanupRuntime',
        'DrainNode',
        'ResumeNode',
    ]);
});

test('node lifecycle enums match the shared Rust protocol', function () {
    expect(array_column(AgentNodeDesiredState::cases(), 'value'))->toBe([
        'active',
        'draining',
        'drained',
        'maintenance',
    ])->and(array_column(AgentNodeStatus::cases(), 'value'))->toBe([
        'ready',
        'busy',
        'degraded',
        'draining',
        'drained',
        'maintenance',
        'offline',
    ])->and(array_column(RepositoryAccess::cases(), 'value'))->toBe([
        'public',
        'temporary_credential',
    ]);
});

test('every protocol v4 command fixture uses a known command type', function () {
    $fixtures = glob(base_path('tests/Fixtures/agent-protocol-v4/commands/*.json'));

    expect($fixtures)->not->toBeEmpty();

    foreach ($fixtures as $path) {
        $command = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        expect(AgentCommandType::tryFrom($command['type']))->not->toBeNull($path)
            ->and(AgentCommandStatus::tryFrom($command['status']))->not->toBeNull($path);
    }
});

test('agent command statuses match the shared Rust protocol', function () {
    expect(array_column(AgentCommandStatus::cases(), 'value'))->toBe([
        'Pending',
        'Claimed',
        'Running',
        'Succeeded',
        'Failed',
        'Cancelled',
        'Expired',
    ]);
});

test('event levels and log streams match the shared Rust protocol', function () {
    expect(array_column(DeploymentEventLevel::cases(), 'value'))->toBe([
        'info',
        'warning',
        'error',
    ])->and(array_column(LogStream::cases(), 'value'))->toBe([
        'stdout',
        'stderr',
        'system',
    ]);
});
