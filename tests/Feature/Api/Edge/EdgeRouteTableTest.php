<?php

declare(strict_types=1);

use App\Actions\Edge\GetEdgeRouteTableAction;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\EdgeToken;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const EDGE_ROUTES_URL = '/api/edge/v1/routes';

beforeEach(function (): void {
    config([
        'sakala.project.default_domain' => 'sakala.app',
        'sakala.edge.routes_cache_seconds' => 0,
    ]);
});

/** An Edge token that is not revoked, and its plaintext. */
function edgeToken(array $attributes = []): string
{
    $plaintext = Str::random(EdgeToken::TOKEN_LENGTH);

    EdgeToken::query()->create([
        'name' => 'edge-'.Str::random(6),
        'token_hash' => EdgeToken::hash($plaintext),
        'token_prefix' => Str::substr($plaintext, 0, EdgeToken::PREFIX_LENGTH),
        ...$attributes,
    ]);

    return $plaintext;
}

function getEdgeRoutes(object $test, ?string $token = null, array $headers = []): TestResponse
{
    return $test->withHeaders([
        'Authorization' => 'Bearer '.($token ?? edgeToken()),
        ...$headers,
    ])->getJson(EDGE_ROUTES_URL);
}

/** A node whose heartbeat reported `$address` as its edge address. */
function edgeNode(?string $address = '10.0.47.201:8180', array $overrides = []): AgentNode
{
    return idleSleepNode([
        'token_hash' => hash_hmac('sha256', Str::random(16), (string) config('app.key')),
        'metadata' => ['runtime' => [AgentNode::EDGE_ADDRESS_METADATA_KEY => $address]],
        ...$overrides,
    ]);
}

/**
 * A running project on the node, at `<slug>.sakala.app` unless given another domain.
 *
 * @return array{Project, Deployment}
 */
function edgeProject(AgentNode $node, array $overrides = []): array
{
    $slug = Str::lower(Str::random(10));

    return idleSleepProject($node, ['slug' => $slug, 'default_domain' => "{$slug}.sakala.app", ...$overrides]);
}

/** A project an admin suspended: the pointer is cleared, the container kept by a sleep. */
function suspendedEdgeProject(AgentNode $node): Project
{
    [$project, $deployment] = edgeProject($node);

    AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::SleepProject,
        'status' => AgentCommandStatus::Succeeded,
        'completed_at' => now()->subMinutes(5),
        'expires_at' => null,
    ]);

    $project->update([
        'status' => ProjectStatus::Suspended,
        'runtime_status' => RuntimeStatus::Stopped,
        'active_deployment_id' => null,
    ]);

    return $project;
}

// ─── Authentication ──────────────────────────────────────────────────────────

test('the route table needs an Edge token', function (array $headers, int $status): void {
    $this->withHeaders($headers)->getJson(EDGE_ROUTES_URL)->assertStatus($status);
})->with([
    'no token' => [[], 401],
    'unknown token' => [['Authorization' => 'Bearer not-a-token'], 401],
    'agent-style header only' => [['X-Agent-Id' => 'agent-1'], 401],
]);

test('a revoked Edge token is refused', function (): void {
    $token = edgeToken(['revoked_at' => now()]);

    getEdgeRoutes($this, $token)->assertForbidden();
});

test('a user session or an agent token does not reach the route table', function (): void {
    $node = edgeNode();

    $this->actingAs(adminApiAdmin(), 'web')->getJson(EDGE_ROUTES_URL)->assertUnauthorized();

    $this->withHeaders(idleSleepHeaders($node))->getJson(EDGE_ROUTES_URL)->assertUnauthorized();
});

test('an Edge token reaches nothing but the Edge routes', function (): void {
    $token = edgeToken();

    $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson('/api/v1/app/projects')->assertUnauthorized();
    $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson('/api/agent/v1/node-state')->assertUnauthorized();
});

test('the route table is read-only', function (): void {
    $this->withHeaders(['Authorization' => 'Bearer '.edgeToken()])->postJson(EDGE_ROUTES_URL)->assertMethodNotAllowed();
});

test('using a token records when, at most once a minute', function (): void {
    $token = edgeToken();

    getEdgeRoutes($this, $token)->assertOk();
    $first = EdgeToken::query()->sole()->last_used_at;

    $this->travel(30)->seconds();
    getEdgeRoutes($this, $token)->assertOk();

    expect($first)->not->toBeNull()
        ->and(EdgeToken::query()->sole()->last_used_at->equalTo($first))->toBeTrue();

    $this->travel(31)->seconds();
    getEdgeRoutes($this, $token)->assertOk();

    expect(EdgeToken::query()->sole()->last_used_at->greaterThan($first))->toBeTrue();
});

test('the route table is rate limited per client', function (): void {
    config(['sakala.rate_limits.edge' => 2]);
    $token = edgeToken();

    getEdgeRoutes($this, $token)->assertOk();
    getEdgeRoutes($this, $token)->assertOk();
    getEdgeRoutes($this, $token)->assertTooManyRequests();
});

// ─── Payload ─────────────────────────────────────────────────────────────────

test('the payload lists one route per project with its node router', function (): void {
    $node = edgeNode('10.0.47.201:8180');
    [$project] = edgeProject($node, ['slug' => 'toko-buku', 'default_domain' => 'toko-buku.sakala.app']);

    $response = getEdgeRoutes($this)->assertOk();

    $response->assertExactJsonStructure([
        'generated_at',
        'revision',
        'expected_minimum_routes',
        'routes' => [['host', 'project_id', 'upstreams']],
        'skipped_routes',
    ]);

    expect($response->json('routes'))->toBe([[
        'host' => 'toko-buku.sakala.app',
        'project_id' => $project->id,
        'upstreams' => ['10.0.47.201:8180'],
    ]])
        ->and($response->json('expected_minimum_routes'))->toBe(1)
        ->and($response->json('skipped_routes'))->toBe([])
        ->and($response->json('revision'))->toMatch('/^[0-9a-f]{64}$/')
        ->and($response->json('generated_at'))->toBe(now()->toRfc3339String());
});

test('the revision is the hash of everything but the time and the revision itself', function (): void {
    $node = edgeNode();
    edgeProject($node);

    $body = getEdgeRoutes($this)->assertOk()->json();
    $content = [
        'expected_minimum_routes' => $body['expected_minimum_routes'],
        'routes' => $body['routes'],
        'skipped_routes' => $body['skipped_routes'],
    ];

    expect($body['revision'])->toBe(hash('sha256', json_encode($content, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
});

test('routes are sorted by host and expected_minimum_routes counts them', function (): void {
    $node = edgeNode();
    edgeProject($node, ['slug' => 'zeta', 'default_domain' => 'zeta.sakala.app']);
    edgeProject($node, ['slug' => 'alpha', 'default_domain' => 'alpha.sakala.app']);
    edgeProject($node, ['slug' => 'mid', 'default_domain' => 'mid.sakala.app']);

    $response = getEdgeRoutes($this)->assertOk();

    expect(array_column($response->json('routes'), 'host'))->toBe(['alpha.sakala.app', 'mid.sakala.app', 'zeta.sakala.app'])
        ->and($response->json('expected_minimum_routes'))->toBe(3);
});

test('sleeping, crashed, and suspended projects keep their route', function (): void {
    $node = edgeNode();
    [$sleeping] = sleepingIdleProject($node, ['slug' => 'tidur', 'default_domain' => 'tidur.sakala.app']);
    [$crashed] = edgeProject($node, ['runtime_status' => RuntimeStatus::Crashed]);
    $suspended = suspendedEdgeProject($node);

    $ids = array_column(getEdgeRoutes($this)->assertOk()->json('routes'), 'project_id');

    expect($ids)->toContain($sleeping->id, $crashed->id, $suspended->id)->toHaveCount(3);
});

test('projects without a workload on a node have no route', function (): void {
    $node = edgeNode();
    [$serving] = edgeProject($node);

    // Never deployed.
    Project::factory()->create(['default_domain' => 'baru.sakala.app']);

    // Stopped: the stop removed the container.
    [$stopped, $stoppedDeployment] = edgeProject($node);
    AgentCommand::factory()->create([
        'project_id' => $stopped->id,
        'deployment_id' => $stoppedDeployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::StopProject,
        'status' => AgentCommandStatus::Succeeded,
        'completed_at' => now()->subMinute(),
        'expires_at' => null,
    ]);
    $stopped->update(['runtime_status' => RuntimeStatus::Stopped, 'active_deployment_id' => null]);

    // Queued deployment that no node took yet.
    [$queued] = edgeProject($node);
    Deployment::query()->whereKey($queued->active_deployment_id)->update(['agent_node_id' => null]);

    // Deleted while serving.
    [$deleted] = edgeProject($node);
    $deleted->delete();

    $routes = getEdgeRoutes($this)->assertOk()->json('routes');

    expect(array_column($routes, 'project_id'))->toBe([$serving->id]);
});

test('a project routes to the node of its active deployment', function (): void {
    $old = edgeNode('10.0.47.10:8180');
    $current = edgeNode('10.0.47.20:8180');
    [$project] = edgeProject($old);
    $redeployed = Deployment::factory()->for($project)->create([
        'agent_node_id' => $current->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 2,
    ]);
    $project->update(['active_deployment_id' => $redeployed->id]);

    expect(getEdgeRoutes($this)->assertOk()->json('routes.0.upstreams'))->toBe(['10.0.47.20:8180']);
});

test('an admin edge address wins over the reported one', function (): void {
    $node = edgeNode('10.0.47.201:8180', ['edge_address_override' => '172.16.0.5:8180']);
    edgeProject($node);

    expect(getEdgeRoutes($this)->assertOk()->json('routes.0.upstreams'))->toBe(['172.16.0.5:8180']);
});

test('a node without an edge address skips its projects and says so', function (): void {
    Log::spy();
    $withAddress = edgeNode('10.0.47.201:8180');
    $without = edgeNode(null);
    [$routed] = edgeProject($withAddress);
    [$skipped] = edgeProject($without, ['slug' => 'tanpa-alamat', 'default_domain' => 'tanpa-alamat.sakala.app']);

    $response = getEdgeRoutes($this)->assertOk();

    expect(array_column($response->json('routes'), 'project_id'))->toBe([$routed->id])
        ->and($response->json('expected_minimum_routes'))->toBe(1)
        ->and($response->json('skipped_routes'))->toBe([[
            'host' => 'tanpa-alamat.sakala.app',
            'project_id' => $skipped->id,
            'reason' => 'node_without_edge_address',
        ]]);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        static fn (string $message, array $context): bool => $context['skipped_count'] === 1,
    );

    // The same problem again is not logged again.
    getEdgeRoutes($this)->assertOk();
    Log::shouldHaveReceived('warning')->once();
});

test('hosts outside the configured project domain are skipped', function (string $domain): void {
    $node = edgeNode();
    [$project] = edgeProject($node, ['default_domain' => $domain]);

    $response = getEdgeRoutes($this)->assertOk();

    expect($response->json('routes'))->toBe([])
        ->and($response->json('skipped_routes.0.project_id'))->toBe($project->id)
        ->and($response->json('skipped_routes.0.reason'))->toBe('invalid_host');
})->with([
    'old domain' => ['lama.run.sakala.dev'],
    'two labels deep' => ['a.b.sakala.app'],
    'the apex' => ['sakala.app'],
    'suffix without a dot' => ['evilsakala.app'],
]);

test('staging routes its own domain only', function (): void {
    config(['sakala.project.default_domain' => 'staging.sakala.app']);
    $node = edgeNode();
    edgeProject($node, ['default_domain' => 'demo.staging.sakala.app']);
    edgeProject($node, ['default_domain' => 'prod.sakala.app']);

    $response = getEdgeRoutes($this)->assertOk();

    expect(array_column($response->json('routes'), 'host'))->toBe(['demo.staging.sakala.app'])
        ->and(array_column($response->json('skipped_routes'), 'host'))->toBe(['prod.sakala.app']);
});

test('without any workload the table is empty, not an error', function (): void {
    $response = getEdgeRoutes($this)->assertOk();

    expect($response->json('routes'))->toBe([])
        ->and($response->json('expected_minimum_routes'))->toBe(0);
});

// ─── Revisions and caching ───────────────────────────────────────────────────

test('If-None-Match with the current revision answers 304 without a body', function (): void {
    $node = edgeNode();
    edgeProject($node);
    $token = edgeToken();

    $first = getEdgeRoutes($this, $token)->assertOk();
    $revision = $first->json('revision');

    expect($first->headers->get('ETag'))->toBe("\"{$revision}\"")
        ->and($first->headers->get('Cache-Control'))->toContain('no-cache');

    $this->travel(10)->seconds();

    $notModified = getEdgeRoutes($this, $token, ['If-None-Match' => "\"{$revision}\""]);

    $notModified->assertStatus(304);
    expect($notModified->getContent())->toBe('')
        ->and($notModified->headers->get('ETag'))->toBe("\"{$revision}\"");
});

test('a change to the routes changes the revision', function (): void {
    $node = edgeNode();
    edgeProject($node);
    $token = edgeToken();
    $revision = getEdgeRoutes($this, $token)->json('revision');

    edgeProject($node);

    $response = getEdgeRoutes($this, $token, ['If-None-Match' => "\"{$revision}\""])->assertOk();

    expect($response->json('revision'))->not->toBe($revision)
        ->and($response->json('expected_minimum_routes'))->toBe(2);
});

test('an outdated or foreign ETag gets the full table', function (): void {
    edgeProject(edgeNode());

    getEdgeRoutes($this, null, ['If-None-Match' => '"'.str_repeat('0', 64).'"'])->assertOk()->assertJsonCount(1, 'routes');
});

test('the built table is reused for the cache window', function (): void {
    config(['sakala.edge.routes_cache_seconds' => 5]);
    $node = edgeNode();
    edgeProject($node);
    $token = edgeToken();

    $first = getEdgeRoutes($this, $token)->assertOk();
    edgeProject($node);

    $cached = getEdgeRoutes($this, $token)->assertOk();
    expect($cached->json('revision'))->toBe($first->json('revision'))
        ->and($cached->json('generated_at'))->toBe($first->json('generated_at'));

    $this->travel(6)->seconds();

    expect(getEdgeRoutes($this, $token)->assertOk()->json('expected_minimum_routes'))->toBe(2);
});

test('a failure is a 5xx and is not cached, never an empty 200', function (): void {
    config(['sakala.edge.routes_cache_seconds' => 5]);
    edgeProject(edgeNode());
    $token = edgeToken();

    // A query of the build fails: the table it reads is gone. Renamed, not
    // dropped, because PostgreSQL refuses to drop a table others reference.
    Schema::rename('agent_commands', 'agent_commands_unavailable');

    $response = getEdgeRoutes($this, $token);

    $response->assertServerError();
    expect($response->json('routes'))->toBeNull()
        ->and(Cache::has(GetEdgeRouteTableAction::CACHE_KEY))->toBeFalse();
});
