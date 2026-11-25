<?php

declare(strict_types=1);

use App\Enums\CustomDomainStatus;
use App\Models\AgentNode;
use App\Models\EdgeToken;
use App\Models\Project;
use App\Models\ProjectDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 * The custom part of the Edge route table (ADR-024), compared with the
 * sakala-edge example copied from sakala-agent main b19b9e0.
 */

beforeEach(function (): void {
    enableCustomDomains();
    config(['sakala.edge.routes_cache_seconds' => 0]);
});

function customRoutesTable(): TestResponse
{
    $plaintext = Str::random(EdgeToken::TOKEN_LENGTH);
    EdgeToken::query()->create([
        'name' => 'edge-'.Str::random(6),
        'token_hash' => EdgeToken::hash($plaintext),
        'token_prefix' => Str::substr($plaintext, 0, EdgeToken::PREFIX_LENGTH),
    ]);

    return test()->withHeaders(['Authorization' => "Bearer {$plaintext}"])->getJson('/api/edge/v1/routes')->assertOk();
}

/** A running project on a node with the Edge address and the given capabilities. */
function routedProject(array $capabilities = CUSTOM_DOMAIN_NODE_CAPABILITIES, string $address = '10.0.47.201:8180'): Project
{
    $node = databaseNode(overrides: [
        'capabilities' => $capabilities,
        'metadata' => ['runtime' => [AgentNode::EDGE_ADDRESS_METADATA_KEY => $address]],
    ]);
    $slug = Str::lower(Str::random(10));

    return idleSleepProject($node, ['slug' => $slug, 'default_domain' => "{$slug}.sakala.app"])[0];
}

test('the table has the custom part in the shape of the sakala-edge example', function (): void {
    $fixture = customDomainFixture('edge/route-table-custom-domains.json');
    $project = routedProject();
    projectDomain($project, ['hostname' => 'www.contoh.com'], verified: true);
    projectDomain(Project::factory()->create(), ['hostname' => 'contoh.net'], verified: true);

    $table = customRoutesTable()->json();

    expect(array_keys($table))->toEqualCanonicalizing([...array_keys($fixture), 'skipped_routes'])
        ->and($table['expected_minimum_custom_routes'])->toBe(2)
        ->and(array_keys($table['custom_routes'][0]))->toBe(array_keys($fixture['custom_routes'][0]))
        ->and($table['custom_routes'])->toBe([
            ['host' => 'contoh.net', 'project_id' => customDomainProjectId('contoh.net'), 'upstreams' => []],
            ['host' => 'www.contoh.com', 'project_id' => $project->id, 'upstreams' => ['10.0.47.201:8180']],
        ]);
});

function customDomainProjectId(string $hostname): string
{
    return ProjectDomain::query()->where('hostname', $hostname)->value('project_id');
}

test('only verified domains of live projects are routed', function (): void {
    $project = routedProject();
    projectDomain($project, ['hostname' => 'a.contoh.com'], verified: true);
    projectDomain($project, ['hostname' => 'pending.contoh.com']);
    projectDomain($project, ['hostname' => 'disabled.contoh.com', 'status' => CustomDomainStatus::Disabled, 'disabled_at' => now()]);
    projectDomain($project, ['hostname' => 'failed.contoh.com', 'status' => CustomDomainStatus::Failed]);
    projectDomain($project, ['hostname' => 'deleted.contoh.com'], verified: true)->delete();
    $gone = routedProject();
    projectDomain($gone, ['hostname' => 'gone.contoh.com'], verified: true);
    $gone->delete();

    expect(collect(customRoutesTable()->json('custom_routes'))->pluck('host')->all())->toBe(['a.contoh.com']);
});

test('a project whose node cannot serve custom domains keeps its site with no upstream', function (): void {
    $project = routedProject(['docker-runtime', 'caddy-file-routing']);
    projectDomain($project, ['hostname' => 'www.contoh.com'], verified: true);

    $table = customRoutesTable()->json();

    expect($table['custom_routes'][0]['upstreams'])->toBe([])
        ->and($table['routes'][0]['upstreams'])->toBe(['10.0.47.201:8180']);
});

test('an empty custom part is still sent while the feature is on', function (): void {
    routedProject();

    customRoutesTable()
        ->assertJsonPath('expected_minimum_custom_routes', 0)
        ->assertJsonPath('custom_routes', []);
});

test('while the feature is off the table and its revision are exactly as before', function (): void {
    $project = routedProject();
    projectDomain($project, ['hostname' => 'www.contoh.com'], verified: true);
    enableCustomDomains(false);

    $table = customRoutesTable()->json();
    $content = [
        'expected_minimum_routes' => $table['expected_minimum_routes'],
        'routes' => $table['routes'],
        'skipped_routes' => $table['skipped_routes'],
    ];

    expect(array_keys($table))->toBe(['generated_at', 'revision', 'expected_minimum_routes', 'routes', 'skipped_routes'])
        ->and($table['revision'])->toBe(hash('sha256', json_encode($content, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
});

test('the revision covers the custom part', function (): void {
    $project = routedProject();
    $before = customRoutesTable()->json('revision');

    $domain = projectDomain($project, ['hostname' => 'www.contoh.com'], verified: true);
    $with = customRoutesTable()->json('revision');

    $domain->delete();

    expect($with)->not->toBe($before)
        ->and(customRoutesTable()->json('revision'))->toBe($before);
});

test('a verified name that a configuration change put under a forbidden zone is left out', function (): void {
    $project = routedProject();
    projectDomain($project, ['hostname' => 'www.contoh.com'], verified: true);
    projectDomain($project, ['hostname' => 'app.example.org'], verified: true);
    config(['sakala.custom_domains.forbidden_zones' => 'sakala.app,example.org']);

    expect(collect(customRoutesTable()->json('custom_routes'))->pluck('host')->all())->toBe(['www.contoh.com']);
});
