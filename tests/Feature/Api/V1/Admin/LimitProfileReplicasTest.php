<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\LimitProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function replicaProfileFields(array $overrides = []): array
{
    return [
        'name' => 'Replika',
        'max_projects' => 5,
        'max_active_containers' => 10,
        'max_active_deployments' => 2,
        'max_memory_mb' => 512,
        'max_cpu_millis' => 1000,
        'reason' => 'Uji load balancer',
        ...$overrides,
    ];
}

test('admins set, change, and clear max_replicas of a profile, with an audit trail', function (): void {
    $admin = adminApiAdmin();

    $id = $this->actingAs($admin, 'web')
        ->postJson('/api/v1/admin/limit-profiles', replicaProfileFields(['max_replicas' => 3]))
        ->assertCreated()
        ->assertJsonPath('data.max_replicas', 3)
        ->json('data.id');

    $this->actingAs($admin, 'web')
        ->patchJson("/api/v1/admin/limit-profiles/{$id}", ['max_replicas' => 4, 'reason' => 'Butuh satu lagi'])
        ->assertOk()
        ->assertJsonPath('data.max_replicas', 4);

    $this->actingAs($admin, 'web')
        ->patchJson("/api/v1/admin/limit-profiles/{$id}", ['max_replicas' => null, 'reason' => 'Matikan replika'])
        ->assertOk()
        ->assertJsonPath('data.max_replicas', null);

    $updates = AuditEvent::query()->where('action', 'limit_profile.updated')->orderBy('id')->pluck('metadata')->all();

    expect(AuditEvent::query()->where('action', 'limit_profile.created')->sole()->metadata['values']['max_replicas'])->toBe(3)
        ->and($updates[0]['from'])->toBe(['max_replicas' => 3])
        ->and($updates[0]['to'])->toBe(['max_replicas' => 4])
        ->and($updates[1]['to'])->toBe(['max_replicas' => null]);
});

test('a profile created without max_replicas allows one replica', function (): void {
    $this->actingAs(adminApiAdmin(), 'web')
        ->postJson('/api/v1/admin/limit-profiles', replicaProfileFields())
        ->assertCreated()
        ->assertJsonPath('data.max_replicas', null);
});

test('max_replicas must be a whole number from one to the ceiling', function (mixed $value): void {
    $this->actingAs(adminApiAdmin(), 'web')
        ->postJson('/api/v1/admin/limit-profiles', replicaProfileFields(['max_replicas' => $value]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['max_replicas']);
})->with([
    'zero' => [0],
    'above the ceiling' => [LimitProfile::MAX_REPLICAS_CEILING + 1],
    'fraction' => [1.5],
    'text' => ['tiga'],
]);
