<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\UsageSignalRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(
        CarbonImmutable::parse('2026-09-14 12:00:00 UTC'),
    );
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('admin can view usage signals', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    UsageSignalRecord::factory()->create([
        'signal_type' => 'deployment_attempt',
        'count' => 5,
        'scope' => 'global',
        'collected_at' => now(),
    ]);

    $response = $this
        ->actingAs($admin, 'web')
        ->getJson('/api/v1/admin/signals');

    $response
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'from',
                'to',
                'signals' => [
                    '*' => [
                        'signal_type',
                        'count',
                        'scope',
                        'scope_id',
                        'tags',
                        'collected_at',
                    ],
                ],
            ],
        ]);
});

test('non admin cannot view usage signals', function (): void {
    $user = User::factory()->create(['role' => UserRole::User]);

    $this
        ->actingAs($user, 'web')
        ->getJson('/api/v1/admin/signals')
        ->assertForbidden();
});

test('unauthenticated user cannot view usage signals', function (): void {
    $this
        ->getJson('/api/v1/admin/signals')
        ->assertUnauthorized();
});

test('usage signals endpoint returns correct date range', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $response = $this
        ->actingAs($admin, 'web')
        ->getJson('/api/v1/admin/signals');

    $response
        ->assertOk()
        ->assertJsonPath('data.from', '2026-09-01T00:00:00+00:00')
        ->assertJsonPath('data.to', '2026-09-14T12:00:00+00:00');
});

test('usage signals respects custom date range', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $response = $this
        ->actingAs($admin, 'web')
        ->getJson('/api/v1/admin/signals?from=2026-09-10&to=2026-09-12');

    $response
        ->assertOk()
        ->assertJsonPath('data.from', '2026-09-10T00:00:00+00:00')
        ->assertJsonPath('data.to', '2026-09-12T00:00:00+00:00');
});

test('usage signals date range rejects invalid input', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this
        ->actingAs($admin, 'web')
        ->getJson('/api/v1/admin/signals?from=2026-09-15&to=2026-09-10')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['to']);
});

test('empty dataset returns empty signals array', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $response = $this
        ->actingAs($admin, 'web')
        ->getJson('/api/v1/admin/signals');

    $response
        ->assertOk()
        ->assertJsonCount(0, 'data.signals');
});

test('signals do not expose personal data or secrets', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    UsageSignalRecord::factory()->create([
        'signal_type' => 'deployment_attempt',
        'count' => 1,
        'scope' => 'project',
        'scope_id' => (string) Str::uuid(),
        'tags' => ['failure_code' => 'build_failed'],
        'collected_at' => now(),
    ]);

    $response = $this
        ->actingAs($admin, 'web')
        ->getJson('/api/v1/admin/signals');

    $response->assertOk();

    $signals = $response->json('data.signals');
    foreach ($signals as $signal) {
        expect($signal['tags'])->not->toContainKey('email');
        expect($signal['tags'])->not->toContainKey('password');
        expect($signal['tags'])->not->toContainKey('secret');
        expect($signal['tags'])->not->toContainKey('token');
        expect($signal['tags'])->not->toContainKey('name');
    }
});

test('signals are ordered by collected_at descending', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    UsageSignalRecord::factory()->create([
        'signal_type' => 'agent_failure',
        'count' => 1,
        'collected_at' => now()->subHours(3),
    ]);

    UsageSignalRecord::factory()->create([
        'signal_type' => 'agent_failure',
        'count' => 2,
        'collected_at' => now()->subHours(1),
    ]);

    $response = $this
        ->actingAs($admin, 'web')
        ->getJson('/api/v1/admin/signals');

    $signals = $response->json('data.signals');
    expect($signals[0]['collected_at'])->toMatch('/2026-09-14T11/');
    expect($signals[1]['collected_at'])->toMatch('/2026-09-14T09/');
});
