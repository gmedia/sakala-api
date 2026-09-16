<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

uses(LazilyRefreshDatabase::class);

test('the verification notification contains a signed verification link', function () {
    $user = User::factory()->unverified()->create(['name' => 'Jane Doe']);
    $notification = new VerifyEmailNotification;
    $mail = $notification->toMail($user);

    expect($notification)->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($mail->subject)->toBe('Verifikasi alamat email Sakala')
        ->and($mail->greeting)->toBe('Halo Jane Doe,')
        ->and($mail->actionText)->toBe('Verifikasi alamat email')
        ->and($mail->actionUrl)->toStartWith(config('app.url').'/auth/email/verify/'.$user->id.'/')
        ->and($mail->actionUrl)->toContain('expires=')
        ->and($mail->actionUrl)->toContain('signature=');
});

test('a guest can verify an account with a valid signed link', function () {
    $user = User::factory()->unverified()->create();
    Event::fake();

    $url = verificationUrl($user);

    $this->get($url)
        ->assertRedirect(config('sakala.console_url').'/email-verified?status=success');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->assertGuest('web');
    Event::assertDispatched(Verified::class, function (Verified $event) use ($user): bool {
        return $event->user->is($user);
    });
});

test('an invalid signature redirects to the verification error page', function () {
    $user = User::factory()->unverified()->create();
    Event::fake();

    $this->get(verificationUrl($user).'&tampered=1')
        ->assertRedirect(config('sakala.console_url').'/email-verified?status=error');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    Event::assertNotDispatched(Verified::class);
});

test('an expired verification link redirects to the verification error page', function () {
    $user = User::factory()->unverified()->create();
    Event::fake();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->subMinute(),
        ['user' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->get($url)
        ->assertRedirect(config('sakala.console_url').'/email-verified?status=error');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    Event::assertNotDispatched(Verified::class);
});

test('a verification link with a mismatched email hash redirects to the error page', function () {
    $user = User::factory()->unverified()->create();
    Event::fake();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['user' => $user->id, 'hash' => sha1('another@example.test')],
    );

    $this->get($url)
        ->assertRedirect(config('sakala.console_url').'/email-verified?status=error');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    Event::assertNotDispatched(Verified::class);
});

test('a verification link for a missing user redirects to the error page', function () {
    Event::fake();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['user' => 999999, 'hash' => sha1('missing@example.test')],
    );

    $this->get($url)
        ->assertRedirect(config('sakala.console_url').'/email-verified?status=error');

    Event::assertNotDispatched(Verified::class);
});

test('verifying an already verified account is idempotent', function () {
    $user = User::factory()->create();
    $verifiedAt = $user->email_verified_at;
    Event::fake();

    $this->get(verificationUrl($user))
        ->assertRedirect(config('sakala.console_url').'/email-verified?status=success');

    expect($user->fresh()->email_verified_at?->equalTo($verifiedAt))->toBeTrue();
    Event::assertNotDispatched(Verified::class);
});

function verificationUrl(User $user): string
{
    return URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['user' => $user->id, 'hash' => sha1($user->email)],
    );
}
