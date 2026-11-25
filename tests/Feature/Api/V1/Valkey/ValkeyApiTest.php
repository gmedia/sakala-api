<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\ValkeyMode;
use App\Enums\ValkeyStatus;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\ManagedValkey;
use App\Models\Project;
use App\Services\Agent\AgentCommandPayloadMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    enableValkey();
});

test('every Valkey endpoint answers 404 while SAKALA_VALKEY_ENABLED is off, as if it did not exist', function (): void {
    enableValkey(false);
    $node = valkeyNode();
    $team = valkeyTeam();
    $valkey = valkeyIn($team['workspace'], $node);
    $project = Project::factory()->inWorkspace($team['workspace'])->create();
    $this->actingAs($team['owner'], 'web');

    $this->getJson(valkeysUrl($team['workspace']->id))->assertNotFound();
    $this->postJson(valkeysUrl($team['workspace']->id), ['name' => 'antrean', 'mode' => 'persistent'])->assertNotFound();
    $this->getJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"))->assertNotFound();
    $this->patchJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"), ['name' => 'baru'])->assertNotFound();
    $this->deleteJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"))->assertNotFound();
    $this->getJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/credentials"))->assertNotFound();
    $this->postJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/rotate-password"))->assertNotFound();
    $this->postJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/attachments/{$project->id}"))->assertNotFound();
    $this->deleteJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/attachments/{$project->id}"))->assertNotFound();

    expect(AgentCommand::query()->count())->toBe(0)
        ->and(ManagedValkey::query()->count())->toBe(1);
});

test('creating a store picks a Valkey node, a random password, and asks the node to create it', function (): void {
    $node = valkeyNode('jkt');
    $team = valkeyTeam();

    $response = $this->actingAs($team['member'], 'web')
        ->postJson(valkeysUrl($team['workspace']->id), ['name' => '  Antrean  ', 'mode' => 'persistent'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Antrean')
        ->assertJsonPath('data.mode', 'persistent')
        ->assertJsonPath('data.status', 'provisioning')
        ->assertJsonPath('data.region', 'jkt')
        ->assertJsonPath('data.maxmemory_mb', ManagedValkey::DEFAULT_SIZE_MB)
        ->assertJsonPath('data.pending_maxmemory_mb', null)
        ->assertJsonPath('data.connection', null)
        ->assertJsonPath('data.password_rotation', null)
        ->assertJsonPath('data.last_failure', null)
        ->assertJsonPath('data.usage.used_memory_bytes', null)
        ->assertJsonPath('data.projects', []);

    expect($response->json('data'))->not->toHaveKeys(['password', 'node_id', 'reserved_maxmemory_mb', 'network', 'host']);

    $valkey = ManagedValkey::query()->findOrFail($response->json('data.id'));
    $command = valkeyCommand($valkey, AgentCommandType::CreateValkey);

    expect($valkey->node_id)->toBe($node->id)
        ->and((string) $valkey->password)->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and($valkey->getRawOriginal('password'))->not->toBe($valkey->password)
        ->and($valkey->reserved_maxmemory_mb)->toBe(64)
        ->and($command->agent_node_id)->toBe($node->id)
        ->and($command->status)->toBe(AgentCommandStatus::Pending)
        ->and($command->expires_at)->toBeNull()
        ->and($command->project_id)->toBeNull()
        ->and($command->deployment_id)->toBeNull()
        ->and($command->payload['valkey_id'])->toBe($valkey->id)
        ->and($command->payload['mode'])->toBe('persistent')
        ->and($command->payload['maxmemory_mb'])->toBe(64)
        // Encrypted at rest, like a database password.
        ->and($command->payload[AgentCommandPayloadMaterializer::PASSWORD_KEY])->not->toBe($valkey->password)
        ->and(Crypt::decryptString($command->payload[AgentCommandPayloadMaterializer::PASSWORD_KEY]))->toBe($valkey->password);

    $audit = AuditEvent::query()->where('action', 'valkey.created')->sole();

    expect($audit->metadata)->toMatchArray(['command_id' => $command->id, 'mode' => 'persistent', 'maxmemory_mb' => 64, 'workspace_id' => $team['workspace']->id])
        ->and(json_encode($audit->metadata))->not->toContain((string) $valkey->password);
});

test('the mode and size are validated, and the size is one of 32, 64, or 128 MB', function (array $body, string $field): void {
    valkeyNode();
    $team = valkeyTeam();

    $this->actingAs($team['owner'], 'web')
        ->postJson(valkeysUrl($team['workspace']->id), $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'no mode' => [['name' => 'a'], 'mode'],
    'unknown mode' => [['name' => 'a', 'mode' => 'lru'], 'mode'],
    'size outside the list' => [['name' => 'a', 'mode' => 'cache', 'maxmemory_mb' => 256], 'maxmemory_mb'],
    'agent minimum is not offered' => [['name' => 'a', 'mode' => 'cache', 'maxmemory_mb' => 16], 'maxmemory_mb'],
    'no name without a project' => [['mode' => 'cache'], 'name'],
]);

test('a name is unique among the workspace\'s live stores', function (): void {
    $node = valkeyNode();
    $team = valkeyTeam();
    valkeyIn($team['workspace'], $node, ['name' => 'antrean']);

    $this->actingAs($team['owner'], 'web')
        ->postJson(valkeysUrl($team['workspace']->id), ['name' => 'antrean', 'mode' => 'cache'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

test('viewers read stores but cannot create or change them, and outsiders get 403', function (): void {
    $node = valkeyNode();
    $team = valkeyTeam();
    $valkey = valkeyIn($team['workspace'], $node);

    $this->actingAs($team['viewer'], 'web')->getJson(valkeysUrl($team['workspace']->id))->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($team['viewer'], 'web')->getJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"))->assertOk();
    $this->actingAs($team['viewer'], 'web')->postJson(valkeysUrl($team['workspace']->id), ['name' => 'x', 'mode' => 'cache'])->assertForbidden();
    $this->actingAs($team['viewer'], 'web')->patchJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"), ['name' => 'y'])->assertForbidden();
    $this->actingAs($team['viewer'], 'web')->deleteJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"))->assertForbidden();
    $this->actingAs($team['viewer'], 'web')->postJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/rotate-password"))->assertForbidden();
    $this->actingAs($team['outsider'], 'web')->getJson(valkeysUrl($team['workspace']->id))->assertForbidden();
    $this->actingAs($team['outsider'], 'web')->getJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"))->assertForbidden();
});

test('a store of another workspace is not found under this one', function (): void {
    $node = valkeyNode();
    $team = valkeyTeam();
    $other = valkeyTeam();
    $foreign = valkeyIn($other['workspace'], $node);

    $this->actingAs($team['owner'], 'web')
        ->getJson(valkeysUrl($team['workspace']->id, "/{$foreign->id}"))
        ->assertNotFound();
});

test('a ready store shows how attached projects reach it, without the password', function (): void {
    $node = valkeyNode();
    $team = valkeyTeam();
    $valkey = valkeyIn($team['workspace'], $node, ['mode' => ValkeyMode::Cache, 'used_memory_bytes' => 1048576, 'container_status' => 'running', 'restart_count' => 0]);

    $response = $this->actingAs($team['viewer'], 'web')
        ->getJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"))
        ->assertOk()
        ->assertJsonPath('data.mode', 'cache')
        ->assertJsonPath('data.connection', ['host' => '10.213.0.2', 'port' => 6379])
        ->assertJsonPath('data.usage.used_memory_bytes', 1048576)
        ->assertJsonPath('data.usage.container_status', 'running');

    expect(json_encode($response->json()))->not->toContain((string) $valkey->password);
});

test('only owners read the password, and every reveal is audited before it is sent', function (): void {
    $node = valkeyNode();
    $team = valkeyTeam();
    $valkey = valkeyIn($team['workspace'], $node);

    $this->actingAs($team['member'], 'web')->getJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/credentials"))->assertForbidden();

    $this->actingAs($team['owner'], 'web')
        ->getJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/credentials"))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.host', '10.213.0.2')
        ->assertJsonPath('data.port', 6379)
        ->assertJsonPath('data.password', $valkey->password)
        ->assertJsonPath('data.url', "redis://:{$valkey->password}@10.213.0.2:6379")
        ->assertJsonPath('data.variables', [
            'REDIS_URL' => "redis://:{$valkey->password}@10.213.0.2:6379",
            'REDIS_HOST' => '10.213.0.2',
            'REDIS_PORT' => '6379',
            'REDIS_PASSWORD' => $valkey->password,
        ]);

    $audit = AuditEvent::query()->where('action', 'valkey.credentials_viewed')->sole();

    expect($audit->actor_id)->toBe((string) $team['owner']->id)
        ->and(json_encode($audit->metadata))->not->toContain((string) $valkey->password);
});

test('credentials of a store still being created answer VALKEY_NOT_READY', function (): void {
    $node = valkeyNode();
    $team = valkeyTeam();
    $valkey = ManagedValkey::factory()->inWorkspace($team['workspace'])->onNode($node)->provisioning()->create();

    $this->actingAs($team['owner'], 'web')
        ->getJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/credentials"))
        ->assertConflict()
        ->assertJsonPath('code', 'VALKEY_NOT_READY')
        ->assertJsonPath('status', 'provisioning');
});

test('renaming changes nothing on the node; the mode cannot be changed', function (): void {
    $node = valkeyNode();
    $team = valkeyTeam();
    $valkey = valkeyIn($team['workspace'], $node);

    $this->actingAs($team['member'], 'web')
        ->patchJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"), ['name' => 'sesi'])
        ->assertOk()
        ->assertJsonPath('data.name', 'sesi');

    $this->patchJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"), ['mode' => 'cache'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('mode');

    expect(AgentCommand::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'valkey.renamed')->count())->toBe(1);
});

test('resizing reserves the larger size until the node reports, and only one resize runs at a time', function (): void {
    $node = valkeyNode();
    $team = valkeyTeam(['max_valkey_memory_mb' => 128]);
    $valkey = valkeyIn($team['workspace'], $node);

    $this->actingAs($team['member'], 'web')
        ->patchJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"), ['maxmemory_mb' => 128])
        ->assertAccepted()
        ->assertJsonPath('data.maxmemory_mb', 64)
        ->assertJsonPath('data.pending_maxmemory_mb', 128);

    $command = valkeyCommand($valkey, AgentCommandType::ResizeValkey);

    expect($command->payload)->toBe(['valkey_id' => $valkey->id, 'maxmemory_mb' => 128])
        ->and($valkey->fresh()->reserved_maxmemory_mb)->toBe(128);

    $this->patchJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"), ['maxmemory_mb' => 32])
        ->assertConflict()
        ->assertJsonPath('code', 'VALKEY_BUSY');

    $command->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);
    completeValkeyCommand($node, $command, valkeyResultFor($valkey, AgentCommandType::ResizeValkey));

    expect($valkey->fresh()->maxmemory_mb)->toBe(128)
        ->and($valkey->fresh()->reserved_maxmemory_mb)->toBe(128);
});

test('growing past the workspace memory answers VALKEY_MEMORY_LIMIT_EXCEEDED; an admin is exempt but not from the node budget', function (): void {
    $node = valkeyNode(capacityMb: 200);
    $team = valkeyTeam(['max_valkey_memory_mb' => 64]);
    $valkey = valkeyIn($team['workspace'], $node);

    $this->actingAs($team['owner'], 'web')
        ->patchJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"), ['maxmemory_mb' => 128])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALKEY_MEMORY_LIMIT_EXCEEDED')
        ->assertJsonPath('limit_mb', 64)
        ->assertJsonPath('current_mb', 0)
        ->assertJsonPath('requested_mb', 128);

    // 128 + 64 = 192 does not fit a node budget of 190, even for an admin.
    $node->update(['metadata' => [...$node->metadata, 'valkey' => ['capacity_mb' => 190, 'available' => true]]]);

    $this->actingAs($team['admin'], 'web')
        ->patchJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"), ['maxmemory_mb' => 128])
        ->assertConflict()
        ->assertJsonPath('code', 'VALKEY_CAPACITY_UNAVAILABLE');

    // It fits 200.
    $node->update(['metadata' => [...$node->metadata, 'valkey' => ['capacity_mb' => 200, 'available' => true]]]);

    $this->patchJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"), ['maxmemory_mb' => 128])
        ->assertAccepted();
});

test('deleting a store asks the node; an attached store answers VALKEY_IN_USE', function (): void {
    $node = valkeyNode();
    $team = valkeyTeam();
    $valkey = valkeyIn($team['workspace'], $node);
    $project = Project::factory()->inWorkspace($team['workspace'])->create(['name' => 'Toko']);
    attachValkey($valkey, $project);

    $this->actingAs($team['member'], 'web')
        ->deleteJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"))
        ->assertConflict()
        ->assertJsonPath('code', 'VALKEY_IN_USE')
        ->assertJsonPath('projects.0.id', $project->id);

    $valkey->attachments()->delete();

    $this->deleteJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"))
        ->assertAccepted()
        ->assertJsonPath('data.status', 'deleting');

    $command = valkeyCommand($valkey, AgentCommandType::DeleteValkey);

    expect($command->payload)->toBe(['valkey_id' => $valkey->id]);

    // Asking again while the delete is on its way changes nothing.
    $this->deleteJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"))->assertAccepted();
    expect(AgentCommand::query()->where('type', AgentCommandType::DeleteValkey)->count())->toBe(1);

    $command->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);
    completeValkeyCommand($node, $command, valkeyResultFor($valkey, AgentCommandType::DeleteValkey));

    $deleted = ManagedValkey::withTrashed()->findOrFail($valkey->id);

    expect($deleted->status)->toBe(ValkeyStatus::Deleted)
        ->and($deleted->trashed())->toBeTrue()
        ->and($deleted->password)->toBeNull()
        ->and(AgentCommand::query()->where('valkey_id', $valkey->id)->get()->every(
            static fn (AgentCommand $command): bool => ! array_key_exists(AgentCommandPayloadMaterializer::PASSWORD_KEY, $command->payload ?? []),
        ))->toBeTrue();

    $this->getJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"))->assertNotFound();
});

test('deleting a store no node has started creating removes it at once', function (): void {
    $node = valkeyNode();
    $team = valkeyTeam();

    $id = $this->actingAs($team['owner'], 'web')
        ->postJson(valkeysUrl($team['workspace']->id), ['name' => 'sementara', 'mode' => 'cache'])
        ->assertCreated()
        ->json('data.id');

    $this->deleteJson(valkeysUrl($team['workspace']->id, "/{$id}"))->assertNoContent();

    $create = AgentCommand::query()->where('valkey_id', $id)->where('type', AgentCommandType::CreateValkey)->sole();

    expect($create->status)->toBe(AgentCommandStatus::Cancelled)
        ->and(ManagedValkey::withTrashed()->findOrFail($id)->status)->toBe(ValkeyStatus::Deleted);
});

test('rotating the password asks the node with a new one and keeps the old one until it reports', function (): void {
    $node = valkeyNode();
    $team = valkeyTeam();
    $valkey = valkeyIn($team['workspace'], $node);
    $old = (string) $valkey->password;

    $this->actingAs($team['member'], 'web')
        ->postJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/rotate-password"), ['redeploy_attached' => true])
        ->assertAccepted()
        ->assertJsonPath('data.password_rotation.status', 'pending')
        ->assertJsonPath('data.password_rotation.redeploy_attached', true);

    $command = valkeyCommand($valkey, AgentCommandType::RotateValkeyPassword);
    $new = Crypt::decryptString($command->payload[AgentCommandPayloadMaterializer::PASSWORD_KEY]);

    expect($new)->toMatch('/^[A-Za-z0-9]{32}$/')->not->toBe($old)
        ->and($valkey->fresh()->password)->toBe($old);

    $this->postJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/rotate-password"))
        ->assertConflict()
        ->assertJsonPath('code', 'VALKEY_BUSY');
});
