<?php

declare(strict_types=1);

use App\Actions\Auth\CreateUserWithUniqueUsernameAction;
use App\Actions\Auth\RegisterAction;
use App\Actions\Legal\RecordLegalAcceptanceAction;
use App\Data\Auth\RegisterData;
use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\Auth\ExistingAccountRegistrationNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

test('a guest can register and receives a queued verification notification', function () {
    Queue::fake();

    $response = $this->postJson(route('api.v1.auth.register'), [
        'name' => '  Jane   Doe ',
        'email' => ' User@Example.TEST ',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'accept_terms' => true,
        'terms_version' => 'syarat-v1',
        'acceptable_use_version' => 'penggunaan-wajar-v1',
    ]);

    $response
        ->assertAccepted()
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
        ->assertJsonValidationErrors(['name', 'email', 'password', 'password_confirmation', 'accept_terms', 'terms_version', 'acceptable_use_version']);

    $this->postJson(route('api.v1.auth.register'), [
        'name' => [],
        'email' => 'not-an-email',
        'password' => 'short',
        'password_confirmation' => 'different',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'password']);
});

test('an existing email gets the same answer as a new one, and the owner an email', function () {
    Notification::fake();
    $owner = User::factory()->create(['email' => 'existing@example.test', 'name' => 'Owner']);
    $passwordHash = $owner->password;

    $payload = fn (string $email): array => [
        'name' => 'Another User',
        'email' => $email,
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'accept_terms' => true,
        'terms_version' => 'syarat-v1',
        'acceptable_use_version' => 'penggunaan-wajar-v1',
    ];

    $existing = $this->postJson(route('api.v1.auth.register'), $payload(' EXISTING@EXAMPLE.TEST '))->assertAccepted();
    $new = $this->postJson(route('api.v1.auth.register'), $payload('existing@example.test'))->assertAccepted();

    expect($existing->json())->toBe($new->json())
        ->and(User::query()->where('email', 'existing@example.test')->count())->toBe(1)
        ->and($owner->fresh()->name)->toBe('Owner')
        ->and($owner->fresh()->password)->toBe($passwordHash);

    Notification::assertSentTo($owner, ExistingAccountRegistrationNotification::class);
    Notification::assertNotSentTo($owner, VerifyEmailNotification::class);
});

test('a new and an existing email answer byte for byte alike', function () {
    Notification::fake();
    User::factory()->create(['email' => 'existing@example.test']);

    $body = fn (string $email): string => (string) $this->postJson(route('api.v1.auth.register'), [
        'name' => 'Same Name',
        'email' => $email,
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'accept_terms' => true,
        'terms_version' => 'syarat-v1',
        'acceptable_use_version' => 'penggunaan-wajar-v1',
    ])->assertAccepted()->getContent();

    $existing = $body('existing@example.test');
    $new = $body('new@example.test');

    expect(str_replace('existing@', 'new@', $existing))->toBe($new);
    Notification::assertSentTo(User::query()->where('email', 'new@example.test')->sole(), VerifyEmailNotification::class);
});

test('the existing account email tells the owner to sign in, not to verify', function () {
    $owner = User::factory()->create(['name' => 'Owner']);
    $mail = (new ExistingAccountRegistrationNotification)->toMail($owner);

    expect($mail->subject)->toBe('Email kamu sudah terdaftar di Sakala')
        ->and($mail->actionUrl)->toBe(config('sakala.console_url').'/login');
});

test('repeated attempts cannot flood the owner mailbox', function () {
    Notification::fake();
    $owner = User::factory()->create(['email' => 'existing@example.test']);
    config()->set('sakala.rate_limits.register', 100);

    foreach ([1, 2, 3] as $ignored) {
        app(RegisterAction::class)->handle(new RegisterData(name: 'X', email: 'existing@example.test', password: 'password123'));
    }

    Notification::assertSentToTimes($owner, ExistingAccountRegistrationNotification::class, 1);
});

test('a database email collision in a race also stays silent', function () {
    Notification::fake();
    $owner = User::factory()->create(['email' => 'race@example.test']);

    $creator = Mockery::mock(CreateUserWithUniqueUsernameAction::class);
    $creator->shouldReceive('handle')->andThrow(new UniqueConstraintViolationException(
        'pgsql',
        'insert into users',
        [],
        new RuntimeException('duplicate key value violates unique constraint "users_email_unique"'),
    ));

    // The pre-check misses it, as it would in a real race.
    $action = new RegisterAction($creator, app(RecordLegalAcceptanceAction::class));
    (fn () => $this->notifyExistingOwner(new RegisterData(name: 'R', email: 'race@example.test', password: 'password123')))->call($action);

    Notification::assertSentTo($owner, ExistingAccountRegistrationNotification::class);
});

test('registration is rate limited by IP', function () {
    config()->set('sakala.rate_limits.register', 1);

    $payload = [
        'name' => 'Rate Limited User',
        'email' => 'rate-limited@example.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'accept_terms' => true,
        'terms_version' => 'syarat-v1',
        'acceptable_use_version' => 'penggunaan-wajar-v1',
    ];

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->postJson(route('api.v1.auth.register'), $payload)
        ->assertAccepted();

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
