<?php

declare(strict_types=1);

use App\Enums\NotificationCategory;
use App\Enums\ValkeyMode;
use App\Enums\ValkeyStatus;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\ManagedValkey;
use App\Support\Database\DatabaseSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    enableValkey();
});

function sendValkeyHeartbeat(AgentNode $node, array $payload): TestResponse
{
    return test()->withHeaders(valkeyNodeHeaders($node))->postJson('/api/agent/v1/heartbeat', $payload);
}

test('the agent heartbeat fixture is accepted: the role stays in the node metadata and each store reading is stored', function (): void {
    $node = valkeyNode();
    $fixture = agentHeartbeatFixture('valkey-host')['metadata']['valkeys'];
    $team = valkeyTeam();
    $persistent = valkeyIn($team['workspace'], $node, ['id' => $fixture[0]['valkey_id'], 'network' => ManagedValkey::networkNameFor($fixture[0]['valkey_id'])]);
    $cache = valkeyIn($team['workspace'], $node, ['id' => $fixture[1]['valkey_id'], 'network' => ManagedValkey::networkNameFor($fixture[1]['valkey_id']), 'mode' => ValkeyMode::Cache]);
    // A store of the same ID on another node is not this node's to report.
    $elsewhere = valkeyIn($team['workspace'], valkeyNode());

    sendValkeyHeartbeat($node, valkeyHeartbeat(valkeys: [...$fixture, [...$fixture[0], 'valkey_id' => $elsewhere->id]]))->assertOk();

    $node->refresh();

    expect($node->valkeyHost()?->capacityMb)->toBe(384)
        ->and($node->valkeyHost()?->available)->toBeTrue()
        ->and($node->metadata)->not->toHaveKey('valkeys')
        ->and($persistent->fresh())
        ->used_memory_bytes->toBe(1048576)
        ->evicted_keys->toBe(0)
        ->errorstat_oom->toBe(0)
        ->connected_clients->toBe(3)
        ->aof_last_write_status->toBe('ok')
        ->container_status->toBe('running')
        ->restart_count->toBe(0)
        ->and($persistent->fresh()->usage_checked_at)->not->toBeNull()
        ->and($cache->fresh())
        ->used_memory_bytes->toBeNull()
        ->container_status->toBe('exited')
        ->restart_count->toBe(4)
        ->and($elsewhere->fresh()->usage_checked_at)->toBeNull();
});

test('a malformed Valkey reading rejects the heartbeat while the feature is on', function (array $item): void {
    $node = valkeyNode();
    $fixture = agentHeartbeatFixture('valkey-host')['metadata']['valkeys'][0];

    sendValkeyHeartbeat($node, valkeyHeartbeat(valkeys: [[...$fixture, ...$item]]))->assertUnprocessable();
})->with([
    'not a UUID' => [['valkey_id' => 'store-1']],
    'unknown mode' => [['mode' => 'lru']],
    'size beyond the agent bound' => [['maxmemory_mb' => 1024]],
    'negative memory' => [['used_memory' => -1]],
    'unknown AOF status' => [['aof_last_write_status' => 'maybe']],
    'string lock' => [['locked' => 'true']],
]);

test('while the feature is off the Valkey keys are ignored, malformed or not, and nothing is stored', function (): void {
    enableValkey(false);
    $node = valkeyNode();
    $valkey = valkeyIn(valkeyTeam()['workspace'], $node);

    sendValkeyHeartbeat($node, valkeyHeartbeat(valkeys: [['valkey_id' => $valkey->id, 'mode' => 'nonsense']]))->assertOk();

    expect($valkey->fresh()->usage_checked_at)->toBeNull()
        ->and($node->fresh()->metadata)->not->toHaveKey('valkeys');
});

test('the usage sweep warns owners and members once at 80 % of maxmemory, and clears the mark below it', function (): void {
    $node = valkeyNode();
    $team = valkeyTeam();
    $valkey = valkeyIn($team['workspace'], $node, ['mode' => ValkeyMode::Persistent, 'used_memory_bytes' => DatabaseSize::bytesOf(52)]);

    $this->artisan('valkeys:check-usage')->assertSuccessful();
    $this->artisan('valkeys:check-usage')->assertSuccessful();

    $notices = DB::table('notifications')->where('notifiable_id', $team['member']->id)->pluck('data')->map(fn (string $data): array => json_decode($data, true));

    expect($valkey->fresh()->memory_warned_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', 'valkey.memory_warning')->count())->toBe(1)
        ->and($notices)->toHaveCount(1)
        ->and($notices[0]['title'])->toBe('Valkey hampir penuh')
        ->and($notices[0]['category'] ?? NotificationCategory::Workspace->value)->toBe(NotificationCategory::Workspace->value)
        ->and($notices[0]['message'])->toContain($valkey->name)->toContain('menolak tulisan baru')
        // Viewers cannot act on it.
        ->and(DB::table('notifications')->where('notifiable_id', $team['viewer']->id)->count())->toBe(0);

    $valkey->update(['used_memory_bytes' => DatabaseSize::bytesOf(10)]);
    $this->artisan('valkeys:check-usage')->assertSuccessful();

    expect($valkey->fresh()->memory_warned_at)->toBeNull();
});

test('a cache store warns that keys will be evicted, and a store below 80 % is left alone', function (): void {
    $node = valkeyNode();
    $team = valkeyTeam();
    $full = valkeyIn($team['workspace'], $node, ['mode' => ValkeyMode::Cache, 'maxmemory_mb' => 32, 'reserved_maxmemory_mb' => 32, 'used_memory_bytes' => DatabaseSize::bytesOf(30)]);
    $calm = valkeyIn($team['workspace'], $node, ['used_memory_bytes' => DatabaseSize::bytesOf(40)]);
    valkeyIn($team['workspace'], $node, ['status' => ValkeyStatus::Deleting, 'used_memory_bytes' => DatabaseSize::bytesOf(64)]);

    $this->artisan('valkeys:check-usage')->assertSuccessful()->expectsOutputToContain('Warned about 1');

    $message = json_decode((string) DB::table('notifications')->where('notifiable_id', $team['owner']->id)->value('data'), true)['message'];

    expect($full->fresh()->memory_warned_at)->not->toBeNull()
        ->and($calm->fresh()->memory_warned_at)->toBeNull()
        ->and($message)->toContain('membuang key');
});
