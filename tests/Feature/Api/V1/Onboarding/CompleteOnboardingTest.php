<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a guest cannot complete onboarding', function (): void {
    $this->postJson(route('api.v1.onboarding.complete'))
        ->assertUnauthorized();
});

test('authenticated user can complete onboarding', function (): void {
    $user = User::factory()->create([
        'onboarding_source' => null,
        'onboarding_role' => null,
        'onboarding_completed_at' => null,
    ]);

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.onboarding.complete'))
        ->assertOk()
        ->assertJson([
            'data' => [
                'id' => $user->id,
            ],
        ]);

    $user->refresh();

    expect($user->onboarding_completed_at)->not->toBeNull();
});

test('user can complete onboarding without completing previous optional steps', function (): void {
    $user = User::factory()->create([
        'onboarding_source' => null,
        'onboarding_role' => null,
        'onboarding_completed_at' => null,
    ]);

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.onboarding.complete'))
        ->assertOk();

    $user->refresh();

    expect($user->onboarding_source)
        ->toBeNull()
        ->and($user->onboarding_role)
        ->toBeNull()
        ->and($user->onboarding_completed_at)
        ->not->toBeNull();
});

test('repeated onboarding completion is idempotent', function (): void {
    $user = User::factory()->create([
        'onboarding_completed_at' => null,
    ]);

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.onboarding.complete'))
        ->assertOk();

    $user->refresh();

    $initialCompletedAt = $user->onboarding_completed_at;

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.onboarding.complete'))
        ->assertOk();

    $user->refresh();

    expect($user->onboarding_completed_at->toIso8601String())
        ->toBe($initialCompletedAt?->toIso8601String());
});

test('a bearer token cannot complete onboarding', function (): void {
    $user = User::factory()->create();

    $token = $user->createToken('onboarding-boundary')->plainTextToken;

    $this->withToken($token)
        ->postJson(route('api.v1.onboarding.complete'))
        ->assertUnauthorized();
});

test('onboarding completion endpoint requires web guard authentication', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson(route('api.v1.onboarding.complete'))
        ->assertUnauthorized();
});
