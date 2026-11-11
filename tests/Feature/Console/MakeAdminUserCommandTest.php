<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function cliAuditMetadata(AuditEvent $event): array
{
    expect($event->actor_type)->toBe('system')
        ->and($event->actor_id)->toBe('cli')
        ->and($event->subject_type)->toBe(User::class)
        ->and($event->ip_address)->toBeNull()
        ->and($event->user_agent)->toBeNull()
        ->and($event->metadata['os_user'] ?? null)->toBeString()->not->toBeEmpty();

    return $event->metadata ?? [];
}

it('promotes an existing user without touching their password', function (): void {
    $user = User::factory()->create(['email' => 'dosen@example.test', 'role' => UserRole::User]);
    $passwordHash = $user->password;

    $this->artisan('user:admin', ['email' => '  Dosen@Example.test ', '--reason' => 'Maintainer baru'])
        ->expectsOutputToContain('Promoted dosen@example.test')
        ->expectsOutputToContain('user.role_changed')
        ->assertSuccessful();

    $user->refresh();

    expect($user->role)->toBe(UserRole::Admin)
        ->and($user->password)->toBe($passwordHash)
        ->and(User::query()->count())->toBe(1);
});

it('records a promotion with the CLI actor, the reason, and the role change', function (): void {
    $user = User::factory()->create(['email' => 'dosen@example.test', 'role' => UserRole::User]);

    $this->artisan('user:admin', ['email' => 'dosen@example.test', '--reason' => 'Maintainer baru'])
        ->assertSuccessful();

    $event = AuditEvent::query()->sole();
    $metadata = cliAuditMetadata($event);

    expect($event->action)->toBe('user.role_changed')
        ->and($event->subject_id)->toBe((string) $user->id)
        ->and(collect($metadata)->except('os_user')->all())->toBe([
            'reason' => 'Maintainer baru',
            'reason_provided' => true,
            'from' => 'user',
            'to' => 'admin',
        ])
        ->and(json_encode($metadata))->not->toContain('dosen@example.test');
});

it('asks for the reason in a terminal when --reason is missing', function (): void {
    User::factory()->create(['email' => 'dosen@example.test', 'role' => UserRole::User]);

    $this->artisan('user:admin', ['email' => 'dosen@example.test'])
        ->expectsQuestion('Reason for this change (stored in the audit log)', '  Tim ops  ')
        ->assertSuccessful();

    expect(AuditEvent::query()->sole()->metadata['reason'])->toBe('Tim ops');
});

it('refuses an empty reason in a terminal and changes nothing', function (): void {
    $user = User::factory()->create(['email' => 'dosen@example.test', 'role' => UserRole::User]);

    $this->artisan('user:admin', ['email' => 'dosen@example.test'])
        ->expectsQuestion('Reason for this change (stored in the audit log)', '')
        ->expectsOutputToContain('A reason is required')
        ->assertFailed();

    expect($user->refresh()->role)->toBe(UserRole::User)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('allows a missing reason only without a terminal and records that it was empty', function (): void {
    User::factory()->create(['email' => 'dosen@example.test', 'role' => UserRole::User]);

    $this->artisan('user:admin', ['email' => 'dosen@example.test', '--no-interaction' => true])
        ->assertSuccessful();

    $metadata = cliAuditMetadata(AuditEvent::query()->sole());

    expect($metadata['reason'])->toBe('')
        ->and($metadata['reason_provided'])->toBeFalse();
});

it('refuses a reason longer than the admin API accepts', function (): void {
    $user = User::factory()->create(['email' => 'dosen@example.test', 'role' => UserRole::User]);

    $this->artisan('user:admin', ['email' => 'dosen@example.test', '--reason' => str_repeat('a', 501)])
        ->expectsOutputToContain('at most 500 characters')
        ->assertFailed();

    expect($user->refresh()->role)->toBe(UserRole::User)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('leaves an existing admin as it is and records nothing', function (): void {
    User::factory()->create(['email' => 'admin@example.test', 'role' => UserRole::Admin]);

    $this->artisan('user:admin', ['email' => 'admin@example.test'])
        ->expectsOutputToContain('already an admin')
        ->assertSuccessful();

    expect(AuditEvent::query()->count())->toBe(0);
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

it('creates a verified admin with a prompted password and records it', function (): void {
    $this->artisan('user:admin', ['email' => 'ops@example.test', '--create' => true, '--name' => 'Tim  Ops'])
        ->expectsQuestion('Password for the new admin account', 'a-long-password-123')
        ->expectsQuestion('Repeat the password', 'a-long-password-123')
        ->expectsQuestion('Reason for this change (stored in the audit log)', 'Akun tim ops')
        ->expectsOutputToContain('Created admin account ops@example.test')
        ->expectsOutputToContain('user.admin_created')
        ->doesntExpectOutputToContain('a-long-password-123')
        ->assertSuccessful();

    $user = User::query()->sole();

    expect($user->role)->toBe(UserRole::Admin)
        ->and($user->name)->toBe('Tim Ops')
        ->and($user->username)->not->toBeEmpty()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('a-long-password-123', (string) $user->password))->toBeTrue()
        ->and($user->requiresLegalAcceptance())->toBeTrue();

    $event = AuditEvent::query()->sole();
    $metadata = cliAuditMetadata($event);

    expect($event->action)->toBe('user.admin_created')
        ->and($event->subject_id)->toBe((string) $user->id)
        ->and(collect($metadata)->except('os_user')->all())->toBe([
            'reason' => 'Akun tim ops',
            'reason_provided' => true,
            'from' => null,
            'to' => 'admin',
        ])
        ->and(json_encode($metadata))->not->toContain('ops@example.test')
        ->not->toContain('a-long-password-123');
});

it('refuses mismatched or weak prompted passwords', function (string $password, string $confirmation): void {
    $this->artisan('user:admin', ['email' => 'ops@example.test', '--create' => true])
        ->expectsQuestion('Password for the new admin account', $password)
        ->expectsQuestion('Repeat the password', $confirmation)
        ->expectsOutputToContain('Nothing was created')
        ->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
})->with([
    'mismatch' => ['a-long-password-123', 'another-password-123'],
    'too short' => ['short', 'short'],
]);

it('creates nothing when the reason is refused', function (): void {
    $this->artisan('user:admin', ['email' => 'ops@example.test', '--create' => true])
        ->expectsQuestion('Password for the new admin account', 'a-long-password-123')
        ->expectsQuestion('Repeat the password', 'a-long-password-123')
        ->expectsQuestion('Reason for this change (stored in the audit log)', '')
        ->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});

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

    $metadata = cliAuditMetadata(AuditEvent::query()->where('action', 'user.admin_created')->sole());

    expect($metadata['reason'])->toBe('')
        ->and($metadata['reason_provided'])->toBeFalse();
});

it('demotes an admin while another admin remains and records it', function (): void {
    User::factory()->create(['role' => UserRole::Admin]);
    $user = User::factory()->create(['email' => 'lama@example.test', 'role' => UserRole::Admin]);

    $this->artisan('user:admin', ['email' => 'lama@example.test', '--demote' => true, '--reason' => 'Selesai bertugas'])
        ->expectsOutputToContain('Demoted lama@example.test')
        ->expectsOutputToContain('user.role_changed')
        ->assertSuccessful();

    expect($user->refresh()->role)->toBe(UserRole::User);

    $event = AuditEvent::query()->sole();
    $metadata = cliAuditMetadata($event);

    expect($event->action)->toBe('user.role_changed')
        ->and($event->subject_id)->toBe((string) $user->id)
        ->and($metadata['reason'])->toBe('Selesai bertugas')
        ->and($metadata['from'])->toBe('admin')
        ->and($metadata['to'])->toBe('user');
});

it('refuses to demote the last admin', function (): void {
    $user = User::factory()->create(['email' => 'admin@example.test', 'role' => UserRole::Admin]);

    $this->artisan('user:admin', ['email' => 'admin@example.test', '--demote' => true, '--reason' => 'Coba'])
        ->expectsOutputToContain('last admin')
        ->assertFailed();

    expect($user->refresh()->role)->toBe(UserRole::Admin)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('leaves a non-admin as it is when demoting', function (): void {
    User::factory()->create(['email' => 'dosen@example.test', 'role' => UserRole::User]);

    $this->artisan('user:admin', ['email' => 'dosen@example.test', '--demote' => true])
        ->expectsOutputToContain('is not an admin')
        ->assertSuccessful();

    expect(AuditEvent::query()->count())->toBe(0);
});

it('refuses to demote an unknown email or to combine --demote with account creation', function (array $options): void {
    User::factory()->create(['role' => UserRole::Admin]);

    $this->artisan('user:admin', ['email' => 'nobody@example.test', '--demote' => true, '--reason' => 'Coba', ...$options])
        ->assertFailed();

    expect(User::query()->count())->toBe(1)
        ->and(AuditEvent::query()->count())->toBe(0);
})->with([
    'unknown email' => [[]],
    'with --create' => [['--create' => true]],
    'with --generate-password' => [['--generate-password' => true]],
]);

it('shows CLI events in the admin audit log under the system actor', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $user = User::factory()->create(['email' => 'dosen@example.test', 'role' => UserRole::User]);

    $this->artisan('user:admin', ['email' => 'dosen@example.test', '--reason' => 'Maintainer baru'])
        ->assertSuccessful();

    $this->actingAs($admin, 'web')
        ->getJson('/api/v1/admin/audit-events?actor_type=system&actor_id=cli')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'user.role_changed')
        ->assertJsonPath('data.0.actor.type', 'system')
        ->assertJsonPath('data.0.actor.id', 'cli')
        ->assertJsonPath('data.0.actor.name', null)
        ->assertJsonPath('data.0.subject.type', 'user')
        ->assertJsonPath('data.0.subject.id', (string) $user->id);
});
