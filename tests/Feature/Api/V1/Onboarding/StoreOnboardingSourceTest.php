<?php

declare(strict_types=1);

use App\Enums\OnboardingSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a guest cannot submit onboarding source', function (): void {
    $this->postJson(route('api.v1.onboarding.source'), ['source' => 'campus'])
        ->assertUnauthorized();
});

test('authenticated user can save a valid onboarding source', function (): void {
    $user = User::factory()->create([
        'onboarding_source' => null,
        'onboarding_completed_at' => null,
    ]);

    $this->actingAs($user)
        ->postJson(route('api.v1.onboarding.source'), [
            'source' => 'campus',
        ])
        ->assertOk()
        ->assertJson([
            'data' => [
                'id' => $user->id,
                'onboarding_source' => 'campus',
            ],
        ]);

    $user->refresh();
    expect($user->onboarding_source)->toBe(OnboardingSource::Campus)
        ->and($user->onboarding_completed_at)->not->toBeNull();
});

test('authenticated user can skip onboarding without dummy values', function (): void {
    $user = User::factory()->create([
        'onboarding_source' => null,
        'onboarding_completed_at' => null,
    ]);

    $this->actingAs($user)
        ->postJson(route('api.v1.onboarding.source'), [
            'skip' => true,
        ])
        ->assertOk()
        ->assertJson([
            'data' => [
                'id' => $user->id,
                'onboarding_source' => null,
            ],
        ]);

    $user->refresh();
    expect($user->onboarding_source)->toBeNull()
        ->and($user->onboarding_completed_at)->not->toBeNull();
});

test('submitting invalid source returns validation error', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.onboarding.source'), [
            'source' => 'invalid_source',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['source']);
});

test('repeated identical onboarding submission is idempotent for the same user', function (): void {
    $user = User::factory()->create([
        'onboarding_source' => null,
        'onboarding_completed_at' => null,
    ]);

    $this->actingAs($user)
        ->postJson(route('api.v1.onboarding.source'), ['source' => 'github'])
        ->assertOk();

    $initialCompletedAt = $user->refresh()->onboarding_completed_at;

    $this->actingAs($user)
        ->postJson(route('api.v1.onboarding.source'), ['source' => 'github'])
        ->assertOk()
        ->assertJson([
            'data' => [
                'onboarding_source' => 'github',
            ],
        ]);

    $user->refresh();
    expect($user->onboarding_source)->toBe(OnboardingSource::Github)
        ->and($user->onboarding_completed_at->toIso8601String())->toBe($initialCompletedAt?->toIso8601String());
});

test('authenticated user can update onboarding source after completion', function (): void {
    $user = User::factory()->create([
        'onboarding_source' => null,
        'onboarding_completed_at' => null,
    ]);

    $this->actingAs($user)
        ->postJson(route('api.v1.onboarding.source'), ['source' => 'github'])
        ->assertOk();

    $initialCompletedAt = $user->refresh()->onboarding_completed_at;

    $this->actingAs($user)
        ->postJson(route('api.v1.onboarding.source'), ['source' => 'workshop'])
        ->assertOk()
        ->assertJson([
            'data' => [
                'onboarding_source' => 'workshop',
            ],
        ]);

    $user->refresh();
    expect($user->onboarding_source)->toBe(OnboardingSource::Workshop)
        ->and($user->onboarding_completed_at->toIso8601String())->toBe($initialCompletedAt?->toIso8601String());
});

test('submitting both source and skip returns validation error', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.onboarding.source'), [
            'source' => 'campus',
            'skip' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['source', 'skip']);
});

test('submitting skip as false returns validation error', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.onboarding.source'), [
            'skip' => false,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['skip']);
});

test('submitting empty payload returns validation error for source', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.onboarding.source'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['source']);
});
