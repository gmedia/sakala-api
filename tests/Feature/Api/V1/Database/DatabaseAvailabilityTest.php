<?php

declare(strict_types=1);

use App\Enums\AgentNodeDesiredState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** What a database node reports once its PostgreSQL instance answers, or not. */
function databaseMetadata(?bool $available): array
{
    return [
        'runtime' => ['region' => null],
        'database' => [
            'endpoint' => ['host' => DATABASE_NODE_HOST, 'port' => DATABASE_NODE_PORT],
            ...($available === null ? [] : ['available' => $available]),
        ],
    ];
}

function databasesAvailable(): mixed
{
    $user = User::factory()->create();

    return test()->actingAs($user, 'web')->getJson('/api/v1/app/pilot-limits')->assertOk()->json('data.databases.available');
}

test('an installation without a database node offers no databases', function (): void {
    expect(databasesAvailable())->toBeFalse();
});

test('an online database node whose instance answers makes databases available', function (): void {
    databaseNode(overrides: ['metadata' => databaseMetadata(true)]);

    expect(databasesAvailable())->toBeTrue();
});

test('databases are unavailable while no eligible online node has a working instance', function (array $overrides): void {
    databaseNode(overrides: $overrides);

    expect(databasesAvailable())->toBeFalse();
})->with([
    'instance down' => [['metadata' => databaseMetadata(false)]],
    'instance never reported' => [['metadata' => databaseMetadata(null)]],
    'node offline' => [['metadata' => databaseMetadata(true), 'last_seen_at' => now()->subHour()]],
    'node drained' => [['metadata' => databaseMetadata(true), 'desired_state' => AgentNodeDesiredState::Drained]],
    'no managed-postgres capability' => [['metadata' => databaseMetadata(true), 'capabilities' => ['docker-runtime']]],
]);

test('one working node is enough when another is down', function (): void {
    databaseNode(overrides: ['metadata' => databaseMetadata(false)]);
    databaseNode(overrides: ['metadata' => databaseMetadata(true)]);

    expect(databasesAvailable())->toBeTrue();
});
