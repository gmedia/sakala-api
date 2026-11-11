<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('promotes an existing user without touching their password', function (): void {
    $user = User::factory()->create(['email' => 'dosen@example.test', 'role' => UserRole::User]);
    $passwordHash = $user->password;

    $this->artisan('user:admin', ['email' => '  Dosen@Example.test '])
        ->expectsOutputToContain('Promoted dosen@example.test')
        ->assertSuccessful();

    $user->refresh();

    expect($user->role)->toBe(UserRole::Admin)
        ->and($user->password)->toBe($passwordHash)
        ->and(User::query()->count())->toBe(1);
});

it('leaves an existing admin as it is', function (): void {
    User::factory()->create(['email' => 'admin@example.test', 'role' => UserRole::Admin]);

    $this->artisan('user:admin', ['email' => 'admin@example.test'])
        ->expectsOutputToContain('already an admin')
        ->assertSuccessful();
});

it('does not create an account for an unknown email unless asked to', function (): void {
    $this->artisan('user:admin', ['email' => 'typo@example.test'])
        ->expectsOutputToContain('pass --create')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('rejects an invalid email', function (): void {
    $this->artisan('user:admin', ['email' => 'not-an-email', '--create' => true, '--generate-password' => true])
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('creates a verified admin with a prompted password', function (): void {
    $this->artisan('user:admin', ['email' => 'ops@example.test', '--create' => true, '--name' => 'Tim  Ops'])
        ->expectsQuestion('Password for the new admin account', 'a-long-password-123')
        ->expectsQuestion('Repeat the password', 'a-long-password-123')
        ->expectsOutputToContain('Created admin account ops@example.test')
        ->doesntExpectOutputToContain('a-long-password-123')
        ->assertSuccessful();

    $user = User::query()->sole();

    expect($user->role)->toBe(UserRole::Admin)
        ->and($user->name)->toBe('Tim Ops')
        ->and($user->username)->not->toBeEmpty()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('a-long-password-123', (string) $user->password))->toBeTrue()
        ->and($user->requiresLegalAcceptance())->toBeTrue();
});

it('refuses mismatched or weak prompted passwords', function (string $password, string $confirmation): void {
    $this->artisan('user:admin', ['email' => 'ops@example.test', '--create' => true])
        ->expectsQuestion('Password for the new admin account', $password)
        ->expectsQuestion('Repeat the password', $confirmation)
        ->expectsOutputToContain('Nothing was created')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'mismatch' => ['a-long-password-123', 'another-password-123'],
    'too short' => ['short', 'short'],
]);

it('needs a password source when it cannot prompt', function (): void {
    $this->artisan('user:admin', ['email' => 'ops@example.test', '--create' => true, '--no-interaction' => true])
        ->expectsOutputToContain('--generate-password')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('prints a generated password once and stores only its hash', function (): void {
    $this->artisan('user:admin', ['email' => 'ops@example.test', '--create' => true, '--generate-password' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Password, shown only this once: ')
        ->assertSuccessful();

    $user = User::query()->sole();

    expect($user->role)->toBe(UserRole::Admin)
        ->and($user->name)->toBe('ops')
        ->and(Hash::isHashed((string) $user->password))->toBeTrue();
});
