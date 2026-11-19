<?php

declare(strict_types=1);

use App\Enums\DatabaseStatus;
use App\Models\AuditEvent;
use App\Models\LimitProfile;
use App\Models\User;
use App\Services\Runtime\PilotRuntimeLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function databaseProfileFields(array $overrides = []): array
{
    return [
        'name' => 'Database',
        'max_projects' => 5,
        'max_active_containers' => 10,
        'max_active_deployments' => 2,
        'max_memory_mb' => 512,
        'max_cpu_millis' => 1000,
        'reason' => 'Uji database',
        ...$overrides,
    ];
}

test('admins set, change, and clear the database limits of a profile, with an audit trail', function (): void {
    $admin = adminApiAdmin();

    $id = $this->actingAs($admin, 'web')
        ->postJson('/api/v1/admin/limit-profiles', databaseProfileFields(['max_databases' => 5, 'max_database_storage_mb' => 5120]))
        ->assertCreated()
        ->assertJsonPath('data.max_databases', 5)
        ->assertJsonPath('data.max_database_storage_mb', 5120)
        ->json('data.id');

    $this->patchJson("/api/v1/admin/limit-profiles/{$id}", ['max_databases' => 0, 'reason' => 'Matikan database'])
        ->assertOk()
        ->assertJsonPath('data.max_databases', 0)
        ->assertJsonPath('data.max_database_storage_mb', 5120);

    $this->patchJson("/api/v1/admin/limit-profiles/{$id}", ['max_databases' => null, 'max_database_storage_mb' => null, 'reason' => 'Ikut global'])
        ->assertOk()
        ->assertJsonPath('data.max_databases', null)
        ->assertJsonPath('data.max_database_storage_mb', null);

    $updates = AuditEvent::query()->where('action', 'limit_profile.updated')->orderBy('id')->pluck('metadata')->all();

    expect(AuditEvent::query()->where('action', 'limit_profile.created')->sole()->metadata['values'])
        ->toMatchArray(['max_databases' => 5, 'max_database_storage_mb' => 5120])
        ->and($updates[0]['to'])->toBe(['max_databases' => 0])
        ->and($updates[1]['to'])->toBe(['max_databases' => null, 'max_database_storage_mb' => null]);
});

test('a profile created without database limits follows the global defaults', function (): void {
    $this->actingAs(adminApiAdmin(), 'web')
        ->postJson('/api/v1/admin/limit-profiles', databaseProfileFields())
        ->assertCreated()
        ->assertJsonPath('data.max_databases', null)
        ->assertJsonPath('data.max_database_storage_mb', null);
});

test('database limits must be whole numbers from zero to the ceiling', function (string $field, mixed $value): void {
    $this->actingAs(adminApiAdmin(), 'web')
        ->postJson('/api/v1/admin/limit-profiles', databaseProfileFields([$field => $value]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'negative count' => ['max_databases', -1],
    'count above the ceiling' => ['max_databases', LimitProfile::MAX_DATABASES_CEILING + 1],
    'fractional count' => ['max_databases', 1.5],
    'negative storage' => ['max_database_storage_mb', -1],
    'storage above the ceiling' => ['max_database_storage_mb', LimitProfile::MAX_DATABASE_STORAGE_MB_CEILING + 1],
    'storage as text' => ['max_database_storage_mb', 'banyak'],
]);

test('pilot-limits shows the effective database quotas and usage of the workspace', function (): void {
    $node = databaseNode();
    $team = databaseTeam(['max_databases' => 4, 'max_database_storage_mb' => 2048]);
    databaseIn($team['workspace'], $node, ['size_bytes' => 1000]);
    databaseIn($team['workspace'], $node, ['size_bytes' => 500, 'status' => DatabaseStatus::Locked]);
    databaseIn($team['workspace'], $node, ['status' => DatabaseStatus::Failed]);

    $this->actingAs($team['viewer'], 'web')
        ->getJson("/api/v1/app/pilot-limits?workspace_id={$team['workspace']->id}")
        ->assertOk()
        ->assertJsonPath('data.quotas.max_databases', 4)
        ->assertJsonPath('data.quotas.current_databases_count', 2)
        ->assertJsonPath('data.quotas.max_database_storage_mb', 2048)
        ->assertJsonPath('data.quotas.current_database_storage_bytes', 1500);
});

test('a workspace without a profile shows the global database defaults, empty values falling back', function (?string $count, ?string $storage, int $expectedCount, int $expectedStorage): void {
    config(['sakala.databases.default_max_count' => $count, 'sakala.databases.default_max_storage_mb' => $storage]);
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->getJson('/api/v1/app/pilot-limits')
        ->assertOk()
        ->assertJsonPath('data.quotas.max_databases', $expectedCount)
        ->assertJsonPath('data.quotas.max_database_storage_mb', $expectedStorage)
        ->assertJsonPath('data.quotas.current_databases_count', 0)
        ->assertJsonPath('data.quotas.current_database_storage_bytes', 0);
})->with([
    'configured' => ['5', '1024', 5, 1024],
    'empty' => ['', '', PilotRuntimeLimitService::DEFAULT_MAX_DATABASES, PilotRuntimeLimitService::DEFAULT_MAX_DATABASE_STORAGE_MB],
    'unset' => [null, null, PilotRuntimeLimitService::DEFAULT_MAX_DATABASES, PilotRuntimeLimitService::DEFAULT_MAX_DATABASE_STORAGE_MB],
    'zero' => ['0', '0', 0, 0],
]);

test('the admin workspace detail shows the database limits and usage', function (): void {
    $node = databaseNode();
    $team = databaseTeam(['max_databases' => 4, 'max_database_storage_mb' => 2048]);
    databaseIn($team['workspace'], $node, ['size_bytes' => 1000]);

    $this->actingAs($team['admin'], 'web')
        ->getJson("/api/v1/admin/workspaces/{$team['workspace']->id}")
        ->assertOk()
        ->assertJsonPath('data.quota.limits.max_databases', 4)
        ->assertJsonPath('data.quota.limits.max_database_storage_mb', 2048)
        ->assertJsonPath('data.quota.usage.databases', 1)
        ->assertJsonPath('data.quota.usage.database_storage_bytes', 1000);
});
