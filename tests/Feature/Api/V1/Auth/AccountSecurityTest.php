<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\Auth\ConfirmEmailChangeNotification;
use App\Notifications\Auth\EmailChangeNoticeNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

const ACCOUNT_ORIGIN = 'http://app.sakala.localhost:5173';

// ─── Change password ─────────────────────────────────────────────────────────

test('a user can change the password with the current one', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->putJson(route('api.v1.auth.password.update'), [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])
        ->assertOk()
        ->assertJsonPath('data.has_password', true);

    expect(Hash::check('new-secure-password', $user->fresh()->password))->toBeTrue();
});

test('a wrong current password changes nothing', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->putJson(route('api.v1.auth.password.update'), [
            'current_password' => 'not-my-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('changing the password requires a session and a confirmation', function (): void {
    $this->putJson(route('api.v1.auth.password.update'), [])->assertUnauthorized();

    $this->actingAs(User::factory()->create(), 'web')
        ->putJson(route('api.v1.auth.password.update'), [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'something-else',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

test('changing the password ends other sessions and keeps this one', function (): void {
    $user = User::factory()->create();
    $otherBrowser = Auth::guard('web')->hashPasswordForCookie($user->password);

    // Control: before the change, that other browser is logged in.
    $this->actingAs($user, 'web')
        ->withHeader('Origin', ACCOUNT_ORIGIN)
        ->withSession(['password_hash_web' => $otherBrowser])
        ->getJson(route('api.v1.auth.user'))
        ->assertOk();

    $this->withSession(['password_hash_web' => $otherBrowser])
        ->putJson(route('api.v1.auth.password.update'), [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])
        ->assertOk()
        ->assertSessionHas('password_hash_web', Auth::guard('web')->hashPasswordForCookie($user->fresh()->password));

    app('auth')->forgetGuards();

    $this->actingAs($user->fresh(), 'web')
        ->withSession(['password_hash_web' => $otherBrowser])
        ->getJson(route('api.v1.auth.user'))
        ->assertUnauthorized();
});

test('changing the password revokes personal access tokens', function (): void {
    $user = User::factory()->create();
    $user->createToken('old');

    $this->actingAs($user, 'web')
        ->putJson(route('api.v1.auth.password.update'), [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])
        ->assertOk();

    expect($user->tokens()->count())->toBe(0);
});

test('an OAuth-only account can set a first password without a current one', function (): void {
    $user = User::factory()->create(['password' => null]);

    $this->actingAs($user, 'web')
        ->getJson(route('api.v1.auth.user'))
        ->assertJsonPath('data.has_password', false);

    $this->putJson(route('api.v1.auth.password.update'), [
        'password' => 'new-secure-password',
        'password_confirmation' => 'new-secure-password',
    ])
        ->assertOk()
        ->assertJsonPath('data.has_password', true);

    expect(Hash::check('new-secure-password', $user->fresh()->password))->toBeTrue();
});

test('changing the password is throttled', function (): void {
    config()->set('sakala.rate_limits.account_security', 1);
    $user = User::factory()->create();

    $payload = ['current_password' => 'wrong', 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'];

    $this->actingAs($user, 'web')->putJson(route('api.v1.auth.password.update'), $payload)->assertUnprocessable();
    $this->putJson(route('api.v1.auth.password.update'), $payload)->assertTooManyRequests();
});

// ─── Change email ────────────────────────────────────────────────────────────

test('changing the email waits for the signed link and warns the old address', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.test']);

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.auth.email.change'), [
            'email' => ' NEW@example.test ',
            'current_password' => 'password',
        ])
        ->assertAccepted()
        ->assertJsonPath('data.email', 'old@example.test')
        ->assertJsonPath('data.pending_email', 'new@example.test');

    expect($user->fresh()->email)->toBe('old@example.test');

    Notification::assertSentTo(
        new AnonymousNotifiable,
        ConfirmEmailChangeNotification::class,
        fn ($notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'new@example.test',
    );
    Notification::assertSentTo(
        new AnonymousNotifiable,
        EmailChangeNoticeNotification::class,
        fn ($notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'old@example.test' && ! $notification->applied,
    );
});

test('the signed link applies the new email and notifies the old address', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create(['email' => 'old@example.test', 'pending_email' => 'new@example.test']);

    $this->get(emailChangeLink($user, 'new@example.test'))
        ->assertRedirect(config('sakala.console_url').'/email-changed?status=success');

    $fresh = $user->fresh();

    expect($fresh->email)->toBe('new@example.test')
        ->and($fresh->pending_email)->toBeNull()
        ->and($fresh->hasVerifiedEmail())->toBeTrue();

    Notification::assertSentTo(
        new AnonymousNotifiable,
        EmailChangeNoticeNotification::class,
        fn ($notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'old@example.test' && $notification->applied,
    );
});

test('a used, tampered, expired, or superseded email link fails', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.test', 'pending_email' => 'new@example.test']);
    $error = config('sakala.console_url').'/email-changed?status=error';

    $this->get(emailChangeLink($user, 'new@example.test').'&tampered=1')->assertRedirect($error);

    $this->get(URL::temporarySignedRoute('email-change.confirm', now()->subMinute(), [
        'user' => $user->id,
        'hash' => sha1('new@example.test'),
    ]))->assertRedirect($error);

    // A newer request replaces what is pending, which kills the older link.
    $older = emailChangeLink($user, 'new@example.test');
    $user->update(['pending_email' => 'newer@example.test']);
    $this->get($older)->assertRedirect($error);

    $link = emailChangeLink($user, 'newer@example.test');
    $this->get($link)->assertRedirect(config('sakala.console_url').'/email-changed?status=success');
    $this->get($link)->assertRedirect($error);

    expect($user->fresh()->email)->toBe('newer@example.test');
});

test('an address taken by another account answers the same and sends no link', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'taken@example.test']);
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.auth.email.change'), [
            'email' => 'taken@example.test',
            'current_password' => 'password',
        ])
        ->assertAccepted();

    Notification::assertNothingSent();
});

test('an address taken after the link was sent cannot be applied', function (): void {
    Notification::fake();
    $user = User::factory()->create(['pending_email' => 'race@example.test']);
    $link = emailChangeLink($user, 'race@example.test');
    User::factory()->create(['email' => 'race@example.test']);

    $this->get($link)->assertRedirect(config('sakala.console_url').'/email-changed?status=error');

    expect($user->fresh()->email)->not->toBe('race@example.test');
});

test('changing the email requires the current password', function (): void {
    Notification::fake();
    $this->postJson(route('api.v1.auth.email.change'), [])->assertUnauthorized();

    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.auth.email.change'), ['email' => 'new@example.test', 'current_password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);

    expect($user->fresh()->pending_email)->toBeNull();
    Notification::assertNothingSent();
});

test('an OAuth-only account must set a password before changing the email', function (): void {
    Notification::fake();
    $user = User::factory()->create(['password' => null]);

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.auth.email.change'), ['email' => 'new@example.test', 'current_password' => 'anything'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);
});

// ─── Forgot and reset password ───────────────────────────────────────────────

test('a forgotten password sends a link that points to the console', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'user@example.test']);

    $this->postJson(route('api.v1.auth.password.forgot'), ['email' => ' USER@example.test '])
        ->assertAccepted()
        ->assertJsonPath('data.request_accepted', true);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
        $url = $notification->toMail($user)->actionUrl;

        return str_starts_with($url, config('sakala.console_url').'/reset-password?token='.$notification->token)
            && str_contains($url, 'email=user%40example.test');
    });
});

test('forgot password answers the same for unknown, throttled, and known emails', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $known = $this->postJson(route('api.v1.auth.password.forgot'), ['email' => $user->email])->assertAccepted();
    $throttled = $this->postJson(route('api.v1.auth.password.forgot'), ['email' => $user->email])->assertAccepted();
    $unknown = $this->postJson(route('api.v1.auth.password.forgot'), ['email' => 'nobody@example.test'])->assertAccepted();

    expect($known->json())->toBe($unknown->json())
        ->and($throttled->json())->toBe($unknown->json());

    Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
});

test('forgot password is throttled and validates input', function (): void {
    config()->set('sakala.rate_limits.password_reset', 1);

    $this->postJson(route('api.v1.auth.password.forgot'), ['email' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    $this->postJson(route('api.v1.auth.password.forgot'), ['email' => 'not-an-email'])
        ->assertTooManyRequests();
});

test('a valid token resets the password and proves the mailbox', function (): void {
    Event::fake([PasswordReset::class]);
    $user = User::factory()->unverified()->create(['email' => 'user@example.test']);
    $user->createToken('old');
    $token = Password::broker()->createToken($user);

    $this->postJson(route('api.v1.auth.password.reset'), [
        'email' => 'user@example.test',
        'token' => $token,
        'password' => 'new-secure-password',
        'password_confirmation' => 'new-secure-password',
    ])->assertNoContent();

    $fresh = $user->fresh();

    expect(Hash::check('new-secure-password', $fresh->password))->toBeTrue()
        ->and($fresh->hasVerifiedEmail())->toBeTrue()
        ->and($fresh->tokens()->count())->toBe(0);
    Event::assertDispatched(PasswordReset::class);

    // U-32: the same account now logs in normally.
    $this->withHeader('Origin', ACCOUNT_ORIGIN)
        ->postJson(route('api.v1.auth.login'), ['email' => 'user@example.test', 'password' => 'new-secure-password'])
        ->assertOk();
});

test('a token only works once', function (): void {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);
    $payload = ['email' => $user->email, 'token' => $token, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'];

    $this->postJson(route('api.v1.auth.password.reset'), $payload)->assertNoContent();
    $this->postJson(route('api.v1.auth.password.reset'), $payload)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_reset_token');
});

test('a wrong token and an unknown email get the same answer', function (): void {
    $user = User::factory()->create();

    $wrongToken = $this->postJson(route('api.v1.auth.password.reset'), [
        'email' => $user->email,
        'token' => 'wrong',
        'password' => 'new-secure-password',
        'password_confirmation' => 'new-secure-password',
    ])->assertUnprocessable();

    $unknownEmail = $this->postJson(route('api.v1.auth.password.reset'), [
        'email' => 'nobody@example.test',
        'token' => 'wrong',
        'password' => 'new-secure-password',
        'password_confirmation' => 'new-secure-password',
    ])->assertUnprocessable();

    expect($wrongToken->json())->toBe($unknownEmail->json())
        ->and(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('resetting a password ends existing sessions', function (): void {
    $user = User::factory()->create();
    $existingSession = Auth::guard('web')->hashPasswordForCookie($user->password);
    $token = Password::broker()->createToken($user);

    $this->postJson(route('api.v1.auth.password.reset'), [
        'email' => $user->email,
        'token' => $token,
        'password' => 'new-secure-password',
        'password_confirmation' => 'new-secure-password',
    ])->assertNoContent();

    $this->actingAs($user->fresh(), 'web')
        ->withHeader('Origin', ACCOUNT_ORIGIN)
        ->withSession(['password_hash_web' => $existingSession])
        ->getJson(route('api.v1.auth.user'))
        ->assertUnauthorized();
});

test('the current user exposes when the account was created', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->getJson(route('api.v1.auth.user'))
        ->assertOk()
        ->assertJsonPath('data.created_at', $user->fresh()->created_at->toAtomString())
        ->assertJsonPath('data.pending_email', null);
});

function emailChangeLink(User $user, string $email): string
{
    return URL::temporarySignedRoute('email-change.confirm', now()->addHour(), [
        'user' => $user->id,
        'hash' => sha1($email),
    ]);
}
