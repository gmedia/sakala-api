<?php

declare(strict_types=1);

use App\Enums\AgentCommandType;
use App\Enums\DatabaseStatus;
use App\Http\Requests\Api\V1\Agent\AgentHeartbeatRequest;
use App\Models\AgentNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** A heartbeat of a database node, with the size list replaced outright. */
function databaseHeartbeat(?array $databases = null, ?array $endpoint = ['host' => '10.0.47.30', 'port' => 5432]): array
{
    $payload = heartbeatPayload(['capabilities' => [AgentCommandType::MANAGED_POSTGRES_CAPABILITY]]);
    $payload['metadata']['database'] = ['endpoint' => $endpoint];

    if ($databases !== null) {
        $payload['metadata']['databases'] = $databases;
        $payload['metadata']['detail_counts']['databases'] = count($databases);
    }

    return $payload;
}

test('a database node reports its endpoint, which is kept, and sizes, which are not', function (): void {
    $node = databaseNode(endpoint: false);
    $database = databaseIn(databaseTeam()['workspace'], $node);

    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', databaseHeartbeat([['name' => $database->pg_database, 'size_bytes' => 8_388_608]], ['host' => 'db-01.internal', 'port' => 6432]))
        ->assertOk();

    $node->refresh();
    $database->refresh();

    expect($node->databaseEndpoint()?->host)->toBe('db-01.internal')
        ->and($node->databaseEndpoint()?->port)->toBe(6432)
        ->and($node->metadata)->not->toHaveKey('databases')
        ->and($node->metadata['detail_counts']['databases'])->toBe(1)
        ->and($database->size_bytes)->toBe(8_388_608)
        ->and($database->size_checked_at?->toDateTimeString())->toBe(now()->toDateTimeString());
});

test('sizes only change databases of the reporting node, by their name on it, in one write', function (): void {
    $node = databaseNode();
    $other = databaseNode();
    $team = databaseTeam();
    $mine = databaseIn($team['workspace'], $node, ['size_bytes' => 1]);
    $deleting = databaseIn($team['workspace'], $node, ['status' => DatabaseStatus::Deleting, 'size_bytes' => 5]);
    $unlisted = databaseIn($team['workspace'], $node, ['size_bytes' => 7]);
    $foreign = databaseIn($team['workspace'], $other, ['size_bytes' => 3]);

    DB::enableQueryLog();

    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', databaseHeartbeat([
            ['name' => $mine->pg_database, 'size_bytes' => 100],
            ['name' => $deleting->pg_database, 'size_bytes' => 200],
            ['name' => $foreign->pg_database, 'size_bytes' => 999],
            ['name' => 'p_unknown', 'size_bytes' => 999],
            ['name' => 'postgres', 'size_bytes' => 999],
        ]))
        ->assertOk();

    $writes = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with($query['query'], 'update "databases"'));

    expect($mine->fresh()->size_bytes)->toBe(100)
        ->and($deleting->fresh()->size_bytes)->toBe(200)
        ->and($unlisted->fresh()->size_bytes)->toBe(7)
        ->and($unlisted->fresh()->size_checked_at)->toBeNull()
        ->and($foreign->fresh()->size_bytes)->toBe(3)
        ->and($writes)->toHaveCount(1);
});

test('a heartbeat without the database fields changes nothing for databases', function (): void {
    $node = databaseNode();
    $database = databaseIn(databaseTeam()['workspace'], $node, ['size_bytes' => 42]);

    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', heartbeatPayload(['capabilities' => [AgentCommandType::MANAGED_POSTGRES_CAPABILITY]]))
        ->assertOk();

    expect($database->fresh()->size_bytes)->toBe(42)
        ->and($node->fresh()->databaseEndpoint())->toBeNull();
});

test('a null endpoint is accepted and clears it', function (): void {
    $node = databaseNode();

    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', databaseHeartbeat(endpoint: null))
        ->assertOk();

    expect($node->fresh()->databaseEndpoint())->toBeNull();
});

test('a malformed database field rejects the whole heartbeat', function (array $mutate): void {
    $node = databaseNode();
    $database = databaseIn(databaseTeam()['workspace'], $node, ['size_bytes' => 42]);
    $payload = databaseHeartbeat([['name' => $database->pg_database, 'size_bytes' => 100]]);
    data_set($payload, $mutate[0], $mutate[1]);

    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', $payload)
        ->assertUnprocessable();

    expect($database->fresh()->size_bytes)->toBe(42);
})->with([
    'host with a port' => [['metadata.database.endpoint.host', '10.0.0.1:5432']],
    'host with a scheme' => [['metadata.database.endpoint.host', 'postgres://db']],
    'bad dotted quad' => [['metadata.database.endpoint.host', '999.1.1.1']],
    'missing host' => [['metadata.database.endpoint', ['port' => 5432]]],
    'port zero' => [['metadata.database.endpoint.port', 0]],
    'port too high' => [['metadata.database.endpoint.port', 65536]],
    'port as text' => [['metadata.database.endpoint.port', 'x']],
    'endpoint not an object' => [['metadata.database.endpoint', 'db:5432']],
    'uppercase name' => [['metadata.databases.0.name', 'P_ABC']],
    'quoted name' => [['metadata.databases.0.name', 'p"; drop']],
    'name too long' => [['metadata.databases.0.name', 'p_'.str_repeat('a', 62)]],
    'negative size' => [['metadata.databases.0.size_bytes', -1]],
    'size as text' => [['metadata.databases.0.size_bytes', 'big']],
    'size too large' => [['metadata.databases.0.size_bytes', AgentHeartbeatRequest::MAX_REPORTED_DATABASE_BYTES + 1]],
    'missing size' => [['metadata.databases.0', ['name' => 'p_abc']]],
    'list not an array' => [['metadata.databases', 'p_abc']],
]);

test('the size list is bounded', function (): void {
    $node = databaseNode();
    $items = array_map(static fn (int $i): array => ['name' => "p_{$i}", 'size_bytes' => 1], range(1, AgentHeartbeatRequest::MAX_DATABASE_ITEMS + 1));

    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', databaseHeartbeat($items))
        ->assertUnprocessable();

    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', databaseHeartbeat(array_slice($items, 0, AgentHeartbeatRequest::MAX_DATABASE_ITEMS)))
        ->assertOk();
});

test('an IPv6 endpoint is accepted without brackets', function (): void {
    $node = databaseNode();

    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', databaseHeartbeat(endpoint: ['host' => 'fd00::30', 'port' => 5432]))
        ->assertOk();

    expect($node->fresh()->databaseEndpoint()?->urlHost())->toBe('[fd00::30]');
});

test('a stored endpoint that became malformed reads as none', function (): void {
    $node = databaseNode(overrides: ['metadata' => ['database' => ['endpoint' => ['host' => 'ok.internal', 'port' => '5432']]]]);

    expect($node->databaseEndpoint())->toBeNull()
        ->and(AgentNode::query()->count())->toBe(1);
});

test('availability is reported as a strict boolean and kept with the node', function (): void {
    $node = databaseNode();
    $payload = databaseHeartbeat();
    $payload['metadata']['database']['available'] = false;

    $this->withHeaders(databaseNodeHeaders($node))->postJson('/api/agent/v1/heartbeat', $payload)->assertOk();

    expect($node->fresh()->databaseAvailable())->toBeFalse();

    $payload['metadata']['database']['available'] = 'no';
    $this->withHeaders(databaseNodeHeaders($node))->postJson('/api/agent/v1/heartbeat', $payload)->assertUnprocessable();
});

test('a heartbeat that carries no fresh size reading keeps the sizes', function (): void {
    $node = databaseNode();
    $database = databaseIn(databaseTeam()['workspace'], $node, ['size_bytes' => 7_952_063]);

    $this->withHeaders(databaseNodeHeaders($node))->postJson('/api/agent/v1/heartbeat', databaseHeartbeat())->assertOk();
    $this->withHeaders(databaseNodeHeaders($node))->postJson('/api/agent/v1/heartbeat', databaseHeartbeat([]))->assertOk();

    expect($database->fresh()->size_bytes)->toBe(7_952_063);
});

test('the agent example heartbeat of a database host is accepted', function (): void {
    $node = databaseNode();
    $database = databaseIn(databaseTeam()['workspace'], $node, ['pg_database' => 'p_8k2m4q7x9z1c3v5b6n0d']);
    $payload = databaseHeartbeat([
        ['name' => 'p_8k2m4q7x9z1c3v5b6n0d', 'size_bytes' => 7952063],
        ['name' => 'p_q1w2e3r4t5y6u7i8o9p0', 'size_bytes' => 412090368],
    ], ['host' => '10.0.47.10', 'port' => 5432]);
    $payload['metadata']['database']['available'] = true;
    $payload['metadata']['runtime'] = ['region' => 'id-jog-1'];

    $this->withHeaders(databaseNodeHeaders($node))->postJson('/api/agent/v1/heartbeat', $payload)->assertOk();

    expect($database->fresh()->size_bytes)->toBe(7952063)
        ->and($node->fresh()->effectiveRegion())->toBe('id-jog-1');
});
