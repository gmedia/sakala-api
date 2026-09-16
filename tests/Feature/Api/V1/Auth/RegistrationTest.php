<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

test('a guest can register and receives a queued verification notification', function () {
    Queue::fake();

    $response = $this->postJson(route('api.v1.auth.register'), [
        'name' => '  Jane   Doe ',
        'email' => ' User@Example.TEST ',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response
        ->assertCreated()
        ->assertJsonStructure(['data' => ['name', 'email', 'verification_required']])
        ->assertJsonPath('data.name', 'Jane Doe')
        ->assertJsonPath('data.email', 'user@example.test')
        ->assertJsonPath('data.verification_required', true)
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.username');

    $user = User::query()->where('email', 'user@example.test')->sole();

    expect($user->name)->toBe('Jane Doe')
        ->and($user->username)->toBe('jane-doe')
        ->and($user->role)->toBe(UserRole::User)
        ->and($user->email_verified_at)->toBeNull()
        ->and(Hash::check('password123', (string) $user->getRawOriginal('password')))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);

    $this->assertGuest('web');

    Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($user): bool {
        return $job->notification instanceof VerifyEmailNotification
            && $job->notifiables->contains(fn (mixed $notifiable): bool => $notifiable instanceof User && $notifiable->is($user))
            && $job->afterCommit === true;
    });
});

test('registration validates required and confirmed credentials', function () {
    $this->postJson(route('api.v1.auth.register'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'password', 'password_confirmation']);

    $this->postJson(route('api.v1.auth.register'), [
        'name' => [],
        'email' => 'not-an-email',
        'password' => 'short',
        'password_confirmation' => 'different',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'password']);
});

test('registration rejects a duplicate email case insensitively', function () {
    User::factory()->create(['email' => 'existing@example.test']);

    $this->postJson(route('api.v1.auth.register'), [
        'name' => 'Another User',
        'email' => ' EXISTING@EXAMPLE.TEST ',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

test('registration is rate limited by IP', function () {
    config()->set('sakala.rate_limits.register', 1);

    $payload = [
        'name' => 'Rate Limited User',
        'email' => 'rate-limited@example.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ];

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->postJson(route('api.v1.auth.register'), $payload)
        ->assertCreated();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->postJson(route('api.v1.auth.register'), [
            ...$payload,
            'email' => 'rate-limited-second@example.test',
        ])
        ->assertTooManyRequests();
});

test('a guest can request another verification notification without revealing account state', function () {
    $unverifiedUser = User::factory()->unverified()->create(['email' => 'unverified@example.test']);
    Queue::fake();

    $response = $this->postJson(route('api.v1.auth.email.verification-notification'), [
        'email' => ' UNVERIFIED@EXAMPLE.TEST ',
    ]);

    $response
        ->assertAccepted()
        ->assertJsonPath('data.request_accepted', true);

    Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($unverifiedUser): bool {
        return $job->notification instanceof VerifyEmailNotification
            && $job->notifiables->contains(fn (mixed $notifiable): bool => $notifiable instanceof User && $notifiable->is($unverifiedUser));
    });
});

test('resending verification for an unknown or verified email has the same accepted response', function () {
    $verifiedUser = User::factory()->create(['email' => 'verified@example.test']);
    Queue::fake();

    $unknownResponse = $this->postJson(route('api.v1.auth.email.verification-notification'), [
        'email' => 'unknown@example.test',
    ]);
    $verifiedResponse = $this->postJson(route('api.v1.auth.email.verification-notification'), [
        'email' => $verifiedUser->email,
    ]);

    $unknownResponse->assertAccepted();
    $verifiedResponse->assertAccepted();
    expect($unknownResponse->json())->toBe($verifiedResponse->json());
    Queue::assertNothingPushed();
});

test('resending verification validates the email address', function () {
    $this->postJson(route('api.v1.auth.email.verification-notification'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    $this->postJson(route('api.v1.auth.email.verification-notification'), [
        'email' => 'not-an-email',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

test('resending verification is rate limited by normalized email and IP', function () {
    config()->set('sakala.rate_limits.email_verification', 1);

    $payload = ['email' => 'resend@example.test'];
    $server = ['REMOTE_ADDR' => '198.51.100.21'];

    $this->withServerVariables($server)
        ->postJson(route('api.v1.auth.email.verification-notification'), $payload)
        ->assertAccepted();

    $this->withServerVariables($server)
        ->postJson(route('api.v1.auth.email.verification-notification'), [
            'email' => ' RESEND@EXAMPLE.TEST ',
        ])
        ->assertTooManyRequests();
});
