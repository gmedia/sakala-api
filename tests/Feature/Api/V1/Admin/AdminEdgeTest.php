<?php

declare(strict_types=1);

use App\Actions\Edge\GetEdgeRouteTableAction;
use App\Enums\UserRole;
use App\Models\EdgeToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const ADMIN_EDGES_URL = '/api/v1/admin/edges';

beforeEach(function (): void {
    $this->freezeSecond();
    config([
        'sakala.project.default_domain' => 'sakala.app',
        'sakala.edge.routes_cache_seconds' => 0,
        'sakala.edge.stale_after_seconds' => 300,
    ]);
});

function adminEdge(string $name, array $attributes = []): EdgeToken
{
    $token = EdgeToken::query()->create([
        'name' => $name,
        'token_hash' => EdgeToken::hash(Str::random(EdgeToken::TOKEN_LENGTH)),
        'token_prefix' => Str::random(EdgeToken::PREFIX_LENGTH),
    ]);
    $token->forceFill($attributes)->save();

    return $token;
}

test('admins see every Edge ordered by name, with what it reported and its health now', function (): void {
    $current = app(GetEdgeRouteTableAction::class)->handle()->revision;
    $older = str_repeat('a', 64);
    $healthy = adminEdge('edge-sgp-1', [
        'last_used_at' => now(),
        'last_version' => '1.0.0',
        'last_applied_revision' => $current,
        'last_status' => 'ok',
        'status_since' => now()->subDay(),
        'token_prefix' => 'prefixsecret',
    ]);
    adminEdge('edge-jkt-1', [
        'last_used_at' => now()->subMinutes(10),
        'last_version' => '0.9.1',
        'last_applied_revision' => $older,
        'last_status' => 'empty_table',
        'status_since' => now()->subMinutes(20),
        'revision_mismatch_since' => now()->subMinutes(12),
    ]);
    adminEdge('edge-new', []);
    adminEdge('edge-old', ['last_used_at' => now()->subDays(3), 'revoked_at' => now()->subDays(2)]);

    $response = $this->actingAs(adminApiAdmin(), 'web')->getJson(ADMIN_EDGES_URL)->assertOk();

    expect($response->json('data.*.name'))->toBe(['edge-jkt-1', 'edge-new', 'edge-old', 'edge-sgp-1'])
        ->and($response->json('data.0'))->toBe([
            'name' => 'edge-jkt-1',
            'last_seen_at' => now()->subMinutes(10)->toAtomString(),
            'last_version' => '0.9.1',
            'applied_revision' => $older,
            'current_revision' => $current,
            'status' => 'empty_table',
            'healthy' => false,
            'unhealthy_reasons' => ['stale', 'status', 'revision_behind'],
            'unhealthy_since' => now()->subMinutes(15)->toAtomString(),
            'revoked' => false,
        ])
        ->and($response->json('data.1'))->toBe([
            'name' => 'edge-new',
            'last_seen_at' => null,
            'last_version' => null,
            'applied_revision' => null,
            'current_revision' => $current,
            'status' => null,
            'healthy' => null,
            'unhealthy_reasons' => [],
            'unhealthy_since' => null,
            'revoked' => false,
        ])
        ->and($response->json('data.2.healthy'))->toBeNull()
        ->and($response->json('data.2.revoked'))->toBeTrue()
        ->and($response->json('data.3.healthy'))->toBeTrue()
        ->and($response->json('data.3.unhealthy_reasons'))->toBe([])
        ->and($response->json('data.3.unhealthy_since'))->toBeNull()
        ->and($response->getContent())->not->toContain($healthy->token_hash)
        ->and($response->getContent())->not->toContain('prefixsecret');
});

test('the list is empty without Edge tokens', function (): void {
    $this->actingAs(adminApiAdmin(), 'web')->getJson(ADMIN_EDGES_URL)->assertOk()->assertExactJson(['data' => []]);
});

test('only admins see the Edge list', function (): void {
    $this->getJson(ADMIN_EDGES_URL)->assertUnauthorized();

    $this->actingAs(User::factory()->create(['role' => UserRole::User]), 'web')
        ->getJson(ADMIN_EDGES_URL)
        ->assertForbidden();
});
