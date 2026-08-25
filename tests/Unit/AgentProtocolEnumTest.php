<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentEventLevel;
use App\Enums\LogStream;

test('agent command types match the shared Rust protocol', function () {
    expect(array_column(AgentCommandType::cases(), 'value'))->toBe([
        'DeployProject',
        'RestartProject',
        'StopProject',
        'SleepProject',
        'WakeProject',
        'HealthCheck',
        'RefreshRoute',
    ]);
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
