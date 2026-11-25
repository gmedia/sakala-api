<?php

declare(strict_types=1);

use App\Enums\ValkeyStatus;
use App\Models\AuditEvent;
use App\Models\LimitProfile;
use App\Models\ManagedValkey;
use App\Models\Project;
use App\Models\User;
use App\Services\Runtime\PilotRuntimeLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    enableValkey();
});

test('the Valkey quota is closed by default: 0 stores and 0 MB without a profile value', function (): void {
    valkeyNode();
    $user = User::factory()->create();
    $team = databaseTeam(['max_valkeys' => null, 'max_valkey_memory_mb' => null]);

    expect(PilotRuntimeLimitService::DEFAULT_MAX_VALKEYS)->toBe(0)
        ->and(PilotRuntimeLimitService::DEFAULT_MAX_VALKEY_MEMORY_MB)->toBe(0)
        ->and(app(PilotRuntimeLimitService::class)->maxValkeysFor(null))->toBe(0)
        ->and(app(PilotRuntimeLimitService::class)->maxValkeyMemoryMbFor(null))->toBe(0);

    $this->actingAs($team['owner'], 'web')
        ->postJson(valkeysUrl($team['workspace']->id), ['name' => 'antrean', 'mode' => 'persistent'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALKEY_LIMIT_EXCEEDED')
        ->assertJsonPath('limit', 0)
        ->assertJsonPath('current', 0);

    // A personal workspace without a profile is closed the same way.
    $this->actingAs($user, 'web')->getJson('/api/v1/app/pilot-limits')
        ->assertOk()
        ->assertJsonPath('data.quotas.max_valkeys', 0)
        ->assertJsonPath('data.quotas.max_valkey_memory_mb', 0);
});

test('the global defaults open every workspace without a profile value; empty values fall back to 0', function (): void {
    config(['sakala.valkey.default_max_count' => 1, 'sakala.valkey.default_max_memory_mb' => 64]);
    $service = app(PilotRuntimeLimitService::class);
    $profile = LimitProfile::factory()->create(['max_valkeys' => null, 'max_valkey_memory_mb' => null]);

    expect($service->maxValkeysFor($profile))->toBe(1)
        ->and($service->maxValkeyMemoryMbFor($profile))->toBe(64);

    config(['sakala.valkey.default_max_count' => '', 'sakala.valkey.default_max_memory_mb' => '']);

    expect($service->maxValkeysFor($profile))->toBe(0)
        ->and($service->maxValkeyMemoryMbFor($profile))->toBe(0);
});

test('a profile opens the workspace: the count and the memory are enforced, and a failed store frees its slot', function (): void {
    valkeyNode();
    $team = valkeyTeam(['max_valkeys' => 2, 'max_valkey_memory_mb' => 128]);
    $this->actingAs($team['owner'], 'web');

    $this->postJson(valkeysUrl($team['workspace']->id), ['name' => 'a', 'mode' => 'cache', 'maxmemory_mb' => 64])->assertCreated();

    $this->postJson(valkeysUrl($team['workspace']->id), ['name' => 'b', 'mode' => 'cache', 'maxmemory_mb' => 128])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALKEY_MEMORY_LIMIT_EXCEEDED')
        ->assertJsonPath('limit_mb', 128)
        ->assertJsonPath('current_mb', 64)
        ->assertJsonPath('requested_mb', 128);

    $this->postJson(valkeysUrl($team['workspace']->id), ['name' => 'b', 'mode' => 'cache', 'maxmemory_mb' => 32])->assertCreated();

    $this->postJson(valkeysUrl($team['workspace']->id), ['name' => 'c', 'mode' => 'cache', 'maxmemory_mb' => 32])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALKEY_LIMIT_EXCEEDED')
        ->assertJsonPath('limit', 2)
        ->assertJsonPath('current', 2);

    $this->getJson('/api/v1/app/pilot-limits?workspace_id='.$team['workspace']->id)
        ->assertOk()
        ->assertJsonPath('data.quotas.max_valkeys', 2)
        ->assertJsonPath('data.quotas.current_valkeys_count', 2)
        ->assertJsonPath('data.quotas.max_valkey_memory_mb', 128)
        ->assertJsonPath('data.quotas.current_valkey_memory_mb', 96);

    ManagedValkey::query()->where('name', 'b')->update(['status' => ValkeyStatus::Failed]);

    $this->postJson(valkeysUrl($team['workspace']->id), ['name' => 'c', 'mode' => 'cache', 'maxmemory_mb' => 32])->assertCreated();
});

test('admins are exempt from the workspace quota but not from the node budget', function (): void {
    // Two 128 MB stores take 2 × (128 + 64) = 384 MB: the whole budget.
    $node = valkeyNode(capacityMb: 384);
    $team = valkeyTeam(['max_valkeys' => 0, 'max_valkey_memory_mb' => 0]);
    $this->actingAs($team['admin'], 'web');

    $this->postJson(valkeysUrl($team['workspace']->id), ['name' => 'a', 'mode' => 'persistent', 'maxmemory_mb' => 128])->assertCreated();
    $this->postJson(valkeysUrl($team['workspace']->id), ['name' => 'b', 'mode' => 'persistent', 'maxmemory_mb' => 128])->assertCreated();

    $this->postJson(valkeysUrl($team['workspace']->id), ['name' => 'c', 'mode' => 'cache', 'maxmemory_mb' => 32])
        ->assertConflict()
        ->assertJsonPath('code', 'VALKEY_CAPACITY_UNAVAILABLE');

    expect(ManagedValkey::budgetMbOnNode($node->id))->toBe(384);
});

test('without a node that runs Valkey, creating one answers VALKEY_UNAVAILABLE and pilot-limits says so', function (): void {
    databaseNode();
    $team = valkeyTeam();

    $this->actingAs($team['owner'], 'web')
        ->postJson(valkeysUrl($team['workspace']->id), ['name' => 'a', 'mode' => 'cache'])
        ->assertConflict()
        ->assertJsonPath('code', 'VALKEY_UNAVAILABLE');

    $this->getJson('/api/v1/app/pilot-limits?workspace_id='.$team['workspace']->id)
        ->assertOk()
        ->assertJsonPath('data.valkeys.enabled', true)
        ->assertJsonPath('data.valkeys.available', false)
        ->assertJsonPath('data.valkeys.sizes_mb', [32, 64, 128])
        ->assertJsonPath('data.valkeys.default_size_mb', 64);
});

test('pilot-limits shows the flag and availability: off, on without a reading, and on with a node', function (): void {
    $team = valkeyTeam();
    $this->actingAs($team['owner'], 'web');

    enableValkey(false);
    valkeyNode();
    $this->getJson('/api/v1/app/pilot-limits')->assertOk()->assertJsonPath('data.valkeys.enabled', false)->assertJsonPath('data.valkeys.available', false);

    enableValkey();
    $this->getJson('/api/v1/app/pilot-limits')->assertOk()->assertJsonPath('data.valkeys.enabled', true)->assertJsonPath('data.valkeys.available', true);
});

test('a node that reports its role down is ranked after one that answers', function (): void {
    $down = valkeyNode(available: false, capacityMb: 1000);
    $up = valkeyNode(capacityMb: 384);
    $team = valkeyTeam();

    $id = $this->actingAs($team['owner'], 'web')
        ->postJson(valkeysUrl($team['workspace']->id), ['name' => 'a', 'mode' => 'cache'])
        ->assertCreated()
        ->json('data.id');

    expect(ManagedValkey::query()->findOrFail($id)->node_id)->toBe($up->id)->not->toBe($down->id);
});

test('a store for a project that already runs goes to the project\'s node, or is refused when that node has no Valkey role', function (): void {
    $team = valkeyTeam();
    $plain = valkeyNode(capabilities: WORKER_SCHEDULE_CAPABILITIES, reported: false);
    $project = Project::factory()->inWorkspace($team['workspace'], $team['owner'])->create();
    $project->deployments()->create([
        'sequence' => 1,
        'status' => 'succeeded',
        'agent_node_id' => $plain->id,
        'branch' => 'main',
        'trigger' => 'manual',
    ]);
    valkeyNode();

    $this->actingAs($team['owner'], 'web')
        ->postJson(valkeysUrl($team['workspace']->id), ['mode' => 'persistent', 'project_id' => $project->id])
        ->assertConflict()
        ->assertJsonPath('code', 'VALKEY_UNAVAILABLE');
});

test('admins set, change, and clear the Valkey limits of a profile, within bounds', function (): void {
    $admin = adminApiAdmin();
    $fields = ['name' => 'Valkey', 'max_projects' => 5, 'max_active_containers' => 10, 'max_active_deployments' => 2, 'max_memory_mb' => 512, 'max_cpu_millis' => 1000, 'reason' => 'Uji Valkey'];

    $id = $this->actingAs($admin, 'web')
        ->postJson('/api/v1/admin/limit-profiles', [...$fields, 'max_valkeys' => 1, 'max_valkey_memory_mb' => 64])
        ->assertCreated()
        ->assertJsonPath('data.max_valkeys', 1)
        ->assertJsonPath('data.max_valkey_memory_mb', 64)
        ->json('data.id');

    $this->patchJson("/api/v1/admin/limit-profiles/{$id}", ['max_valkeys' => null, 'max_valkey_memory_mb' => null, 'reason' => 'Ikut global'])
        ->assertOk()
        ->assertJsonPath('data.max_valkeys', null)
        ->assertJsonPath('data.max_valkey_memory_mb', null);

    $this->postJson('/api/v1/admin/limit-profiles', [...$fields, 'max_valkeys' => LimitProfile::MAX_VALKEYS_CEILING + 1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('max_valkeys');

    $this->postJson('/api/v1/admin/limit-profiles', [...$fields, 'max_valkey_memory_mb' => -1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('max_valkey_memory_mb');

    expect(AuditEvent::query()->where('action', 'limit_profile.created')->sole()->metadata['values'])
        ->toMatchArray(['max_valkeys' => 1, 'max_valkey_memory_mb' => 64]);
});
