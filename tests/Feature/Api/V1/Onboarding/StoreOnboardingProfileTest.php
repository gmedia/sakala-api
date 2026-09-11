<?php

declare(strict_types=1);

use App\Enums\OnboardingProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a guest cannot submit onboarding profile', function (): void {
    $this->postJson(route('api.v1.onboarding.profile'), [
        'name' => 'Maman Adi Firmansyah',
        'role' => 'developer',
    ])->assertUnauthorized();
});

test('authenticated user can save a valid onboarding profile', function (): void {
    $user = User::factory()->create([
        'onboarding_role' => null,
        'onboarding_completed_at' => null,
    ]);

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.onboarding.profile'), [
            'name' => 'Maman Adi Firmansyah',
            'role' => 'developer',
        ])
        ->assertOk()
        ->assertJson([
            'data' => [
                'id' => $user->id,
                'name' => 'Maman Adi Firmansyah',
                'onboarding_role' => 'developer',
            ],
        ]);

    $user->refresh();

    expect($user->name)
        ->toBe('Maman Adi Firmansyah')
        ->and($user->onboarding_role)
        ->toBe(OnboardingProfile::Developer)
        ->and($user->onboarding_completed_at)
        ->toBeNull();
});

test('authenticated user can skip onboarding profile without changing name', function (): void {
    $user = User::factory()->create([
        'name' => 'Existing Name',
        'onboarding_role' => null,
        'onboarding_completed_at' => null,
    ]);

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.onboarding.profile'), [
            'skip' => true,
        ])
        ->assertOk()
        ->assertJson([
            'data' => [
                'id' => $user->id,
                'name' => 'Existing Name',
                'onboarding_role' => null,
            ],
        ]);

    $user->refresh();

    expect($user->name)
        ->toBe('Existing Name')
        ->and($user->onboarding_role)
        ->toBeNull()
        ->and($user->onboarding_completed_at)
        ->toBeNull();
});

test('submitting invalid onboarding role returns validation error', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.onboarding.profile'), [
            'name' => 'Maman Adi Firmansyah',
            'role' => 'invalid_role',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role']);
});

test('submitting profile without name returns validation error', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.onboarding.profile'), [
            'role' => 'developer',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('submitting profile without role returns validation error', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.onboarding.profile'), [
            'name' => 'Maman Adi Firmansyah',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role']);
});

test('submitting name and skip returns validation error', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.onboarding.profile'), [
            'name' => 'Maman Adi Firmansyah',
            'skip' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'skip']);
});

test('submitting role and skip returns validation error', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.onboarding.profile'), [
            'role' => 'developer',
            'skip' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role', 'skip']);
});

test('submitting skip as false returns validation error', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.onboarding.profile'), [
            'skip' => false,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['skip']);
});

test('repeated identical onboarding profile submission is idempotent for the same user', function (): void {
    $user = User::factory()->create([
        'onboarding_role' => null,
        'onboarding_completed_at' => null,
    ]);

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.onboarding.profile'), [
            'name' => 'Maman Adi Firmansyah',
            'role' => 'developer',
        ])
        ->assertOk();

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.onboarding.profile'), [
            'name' => 'Maman Adi Firmansyah',
            'role' => 'developer',
        ])
        ->assertOk();

    $user->refresh();

    expect($user->name)
        ->toBe('Maman Adi Firmansyah')
        ->and($user->onboarding_role)
        ->toBe(OnboardingProfile::Developer)
        ->and($user->onboarding_completed_at)
        ->toBeNull();
});
