<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Notifications\UserAlertNotification;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

/** Evaluates config/sakala.php under the given env, the way a booting app would. */
function emailDeliveryFromEnv(?string $mailer, ?string $flag): bool
{
    $names = ['MAIL_MAILER' => $mailer, 'SAKALA_EMAIL_DELIVERY_ENABLED' => $flag];
    $previous = [];

    foreach ($names as $name => $value) {
        $previous[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];

        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        } else {
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }
    }

    try {
        return (require base_path('config/sakala.php'))['email_delivery_enabled'];
    } finally {
        foreach ($previous as $name => [$env, $server, $process]) {
            unset($_ENV[$name], $_SERVER[$name]);

            if ($env !== null) {
                $_ENV[$name] = $env;
            }
            if ($server !== null) {
                $_SERVER[$name] = $server;
            }

            putenv($process === false ? $name : "{$name}={$process}");
        }
    }
}

test('the flag follows the mailer and the env overrides it', function (?string $mailer, ?string $flag, bool $expected): void {
    expect(emailDeliveryFromEnv($mailer, $flag))->toBe($expected);
})->with([
    'smtp, unset' => ['smtp', null, true],
    'failover, unset' => ['failover', null, true],
    'log, unset' => ['log', null, false],
    'array, unset' => ['array', null, false],
    'no mailer falls back to log' => [null, null, false],
    'empty flag means unset, smtp' => ['smtp', '', true],
    'empty flag means unset, log' => ['log', '', false],
    'log forced on, as local development does' => ['log', 'true', true],
    'smtp forced off' => ['smtp', 'false', false],
    'an unreadable value stays closed' => ['smtp', 'maybe', false],
]);

/** @return array<string, array{string, array<string, string>}> */
function guestEmailEndpoints(): array
{
    return [
        'register' => ['api.v1.auth.register', [
            'name' => 'Rani',
            'email' => 'rani@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => true,
            'terms_version' => 'syarat-v1',
            'acceptable_use_version' => 'penggunaan-wajar-v1',
        ]],
        'forgot password' => ['api.v1.auth.password.forgot', ['email' => 'rani@example.test']],
        'resend verification' => ['api.v1.auth.email.verification-notification', ['email' => 'rani@example.test']],
    ];
}

// ─── Public options ──────────────────────────────────────────────────────────

test('auth options are public, need no database, and report what is configured', function (): void {
    config()->set('sakala.email_delivery_enabled', false);
    config()->set('services.github_app', ['client_id' => 'id', 'client_secret' => 'secret', 'redirect' => 'http://api.test/callback']);
    config()->set('services.google', ['client_id' => null, 'client_secret' => null, 'redirect' => 'http://api.test/callback']);

    DB::enableQueryLog();

    $this->getJson(route('api.v1.auth.options'))
        ->assertOk()
        ->assertExactJson(['data' => [
            'email_delivery_enabled' => false,
            'providers' => ['github' => true, 'google' => false],
            'legal' => [
                'terms_version' => 'syarat-v1',
                'acceptable_use_version' => 'penggunaan-wajar-v1',
                'privacy_notice_version' => 'privasi-v1',
            ],
        ]]);

    expect(DB::getQueryLog())->toBe([]);
});

test('auth options report delivery and providers when they are on', function (): void {
    config()->set('sakala.email_delivery_enabled', true);
    config()->set('services.github_app', ['client_id' => '', 'client_secret' => 'secret', 'redirect' => 'http://api.test/callback']);
    config()->set('services.google', ['client_id' => 'id', 'client_secret' => 'secret', 'redirect' => 'http://api.test/callback']);

    $this->getJson(route('api.v1.auth.options'))
        ->assertOk()
        ->assertExactJson(['data' => [
            'email_delivery_enabled' => true,
            'providers' => ['github' => false, 'google' => true],
            'legal' => [
                'terms_version' => 'syarat-v1',
                'acceptable_use_version' => 'penggunaan-wajar-v1',
                'privacy_notice_version' => 'privasi-v1',
            ],
        ]]);
});

// ─── Delivery off ────────────────────────────────────────────────────────────

test('endpoints that send email answer 503 while delivery is off', function (string $route, array $payload): void {
    config()->set('sakala.email_delivery_enabled', false);
    Notification::fake();

    $this->postJson(route($route), $payload)
        ->assertStatus(503)
        ->assertExactJson([
            'code' => 'EMAIL_DELIVERY_UNAVAILABLE',
            'message' => 'Sakala belum bisa mengirim email untuk saat ini, jadi pendaftaran, reset kata sandi, verifikasi, dan ganti email lewat email belum tersedia. Kalau belum punya akun, masuk lewat GitHub atau hubungi admin Sakala.',
        ]);

    Notification::assertNothingSent();
    expect(User::query()->count())->toBe(0);
})->with(guestEmailEndpoints());

test('the refusal does not reveal whether the account exists', function (string $route, array $payload): void {
    config()->set('sakala.email_delivery_enabled', false);
    Notification::fake();

    $unknown = $this->postJson(route($route), $payload);

    User::factory()->unverified()->create(['email' => 'rani@example.test']);
    $existing = $this->postJson(route($route), $payload);

    $malformed = $this->postJson(route($route), ['email' => 'not-an-email']);

    expect($existing->getStatusCode())->toBe(503)
        ->and($existing->getContent())->toBe($unknown->getContent())
        ->and($malformed->getStatusCode())->toBe(503)
        ->and($malformed->getContent())->toBe($unknown->getContent());

    Notification::assertNothingSent();
})->with(guestEmailEndpoints());

test('email change answers 503 for a session and stays closed to guests', function (): void {
    config()->set('sakala.email_delivery_enabled', false);
    Notification::fake();
    $user = User::factory()->create();
    $payload = ['email' => 'baru@example.test', 'current_password' => 'password'];

    $this->postJson(route('api.v1.auth.email.change'), $payload)->assertUnauthorized();

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.auth.email.change'), $payload)
        ->assertStatus(503)
        ->assertJsonPath('code', 'EMAIL_DELIVERY_UNAVAILABLE');

    Notification::assertNothingSent();
    expect($user->fresh()->pending_email)->toBeNull();
});

test('a verified account can still log in with a password while delivery is off', function (): void {
    config()->set('sakala.email_delivery_enabled', false);
    $user = User::factory()->create();

    $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->postJson(route('api.v1.auth.login'), ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.email', $user->email);

    $this->assertAuthenticatedAs($user, 'web');
});

test('an unverified login is not told to open or resend a link while delivery is off', function (): void {
    config()->set('sakala.email_delivery_enabled', false);
    $user = User::factory()->unverified()->create();

    $response = $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->postJson(route('api.v1.auth.login'), ['email' => $user->email, 'password' => 'password'])
        ->assertForbidden()
        ->assertJsonPath('code', 'email_not_verified');

    expect($response->json('message'))->not->toContain('kirim ulang')->toContain('belum bisa mengirim');
    $this->assertGuest('web');
});

test('changing the password works while delivery is off and sends no email', function (): void {
    config()->set('sakala.email_delivery_enabled', false);
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->putJson(route('api.v1.auth.password.update'), [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])
        ->assertOk();

    expect(Hash::check('new-secure-password', $user->fresh()->password))->toBeTrue();
    Notification::assertSentTo(
        $user,
        UserAlertNotification::class,
        static fn (UserAlertNotification $notification, array $channels): bool => ! in_array('mail', $channels, true),
    );
});

test('a notice that cannot be sent does not fail the password change', function (): void {
    config()->set('sakala.email_delivery_enabled', false);
    $user = User::factory()->create();

    $this->app->instance(Dispatcher::class, new class implements Dispatcher
    {
        public function send($notifiables, $notification): void
        {
            throw new RuntimeException('Connection could not be established.');
        }

        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            throw new RuntimeException('Connection could not be established.');
        }
    });

    $this->actingAs($user, 'web')
        ->putJson(route('api.v1.auth.password.update'), [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])
        ->assertOk();

    expect(Hash::check('new-secure-password', $user->fresh()->password))->toBeTrue();
});

test('a reset link that already arrived still works while delivery is off', function (): void {
    config()->set('sakala.email_delivery_enabled', false);
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $this->postJson(route('api.v1.auth.password.reset'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-secure-password',
        'password_confirmation' => 'new-secure-password',
    ])->assertNoContent();
});

// ─── Delivery on ─────────────────────────────────────────────────────────────

test('endpoints that send email work while delivery is on', function (): void {
    config()->set('sakala.email_delivery_enabled', true);
    Notification::fake();
    $unverified = User::factory()->unverified()->create();
    $verified = User::factory()->create();

    $this->postJson(route('api.v1.auth.register'), [
        'name' => 'Rani',
        'email' => 'rani@example.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'accept_terms' => true,
        'terms_version' => 'syarat-v1',
        'acceptable_use_version' => 'penggunaan-wajar-v1',
    ])->assertAccepted();

    $this->postJson(route('api.v1.auth.email.verification-notification'), ['email' => $unverified->email])
        ->assertAccepted();
    Notification::assertSentTo($unverified, VerifyEmailNotification::class);

    $this->postJson(route('api.v1.auth.password.forgot'), ['email' => $verified->email])
        ->assertAccepted();
    Notification::assertSentTo($verified, ResetPasswordNotification::class);

    $this->actingAs($verified, 'web')
        ->postJson(route('api.v1.auth.email.change'), ['email' => 'baru@example.test', 'current_password' => 'password'])
        ->assertAccepted()
        ->assertJsonPath('data.pending_email', 'baru@example.test');
});
