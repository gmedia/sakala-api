<?php

declare(strict_types=1);

use App\Actions\Onboarding\CompleteOnboardingAction;
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

test('stale user instance cannot overwrite onboarding completion timestamp', function (): void {
    $user = User::factory()->create([
        'onboarding_completed_at' => null,
    ]);

    $firstInstance = $user->fresh();
    $secondInstance = $user->fresh();

    $action = app(CompleteOnboardingAction::class);

    $this->travelTo(now()->startOfMinute());

    $action->handle($firstInstance);

    $firstCompletedAt = $firstInstance->fresh()->onboarding_completed_at;

    $this->travel(5)->minutes();

    $action->handle($secondInstance);

    $finalCompletedAt = $user->fresh()->onboarding_completed_at;

    expect($finalCompletedAt->toIso8601String())
        ->toBe($firstCompletedAt->toIso8601String());
});
