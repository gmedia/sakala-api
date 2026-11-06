<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\Auth\ConfirmEmailChangeNotification;
use App\Notifications\Auth\EmailChangeNoticeNotification;
use App\Notifications\Auth\ExistingAccountRegistrationNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The real templates must not depend on either of these being right.
    config([
        'app.name' => 'Laravel',
        'app.url' => 'https://api.example.test',
        'sakala.console_url' => 'https://console.example.test',
    ]);
    URL::forceRootUrl('https://api.example.test');
});

function mailFor(Notification $notification, User $user): MailMessage
{
    return $notification->toMail($user);
}

function htmlOf(MailMessage $mail): string
{
    return (string) $mail->render();
}

function textOf(MailMessage $mail): string
{
    return (string) app(Markdown::class)->renderText($mail->markdown, $mail->data());
}

dataset('transactional mail', [
    'verification' => ['verification', fn (): Notification => new VerifyEmailNotification, 1],
    'password reset' => ['password-reset', fn (): Notification => new ResetPasswordNotification('reset-token'), 1],
    'email change confirmation' => ['email-change-confirm', fn (): Notification => new ConfirmEmailChangeNotification('Jane Doe', 'https://api.example.test/confirm?signature=abc&expires=1'), 1],
    'email change requested' => ['email-change-requested', fn (): Notification => new EmailChangeNoticeNotification('Jane Doe', 'baru@example.test', false), 1],
    'email change applied' => ['email-change-applied', fn (): Notification => new EmailChangeNoticeNotification('Jane Doe', 'baru@example.test', true), 0],
    'existing account' => ['existing-account', fn (): Notification => new ExistingAccountRegistrationNotification, 1],
]);

test('every email reads as Sakala, in Indonesian, with at most one button', function (string $name, Closure $build, int $buttons): void {
    $mail = mailFor($build(), User::factory()->create(['name' => 'Jane Doe']));
    $html = htmlOf($mail);
    $text = textOf($mail);

    // SAKALA_MAIL_PREVIEW_DIR=/tmp/x vendor/bin/pest --filter=TransactionalMail writes them for a look.
    if (is_string($directory = getenv('SAKALA_MAIL_PREVIEW_DIR'))) {
        file_put_contents("{$directory}/{$name}.html", $html);
        file_put_contents("{$directory}/{$name}.txt", "Subject: {$mail->subject}\n\n{$text}");
    }

    expect($mail->subject)->toContain('Sakala')
        ->and($mail->greeting)->toBe('Halo Jane Doe,')
        ->and(substr_count($html, 'class="button'))->toBe($buttons)
        ->and($html)->toContain('<title>Sakala</title>')
        ->and($html)->toContain('<a href="https://console.example.test"')
        ->and($html)->not->toContain('<img');

    foreach ([$mail->subject, $html, $text] as $part) {
        expect($part)
            ->not->toContain('Laravel')
            ->not->toContain('Sakala API')
            ->not->toContain('Regards')
            ->not->toContain('All rights reserved')
            ->not->toContain('If you')
            ->not->toMatch('/\bAnda\b/');
    }

    expect($text)->toContain('Sakala')
        ->and($text)->not->toContain('](')
        ->and($text)->not->toContain('<a ');
})->with('transactional mail');

test('the button and the fallback link both go where the mail says', function (): void {
    $user = User::factory()->create(['email' => 'jane+reset@example.test']);
    $html = htmlOf(mailFor(new ResetPasswordNotification('reset-token'), $user));

    $expected = htmlspecialchars('https://console.example.test/reset-password?token=reset-token&email=jane%2Breset%40example.test');

    expect(substr_count($html, 'href="'.$expected.'"'))->toBe(2);
});

test('a stranger triggering these mails is told how to treat them', function (string $name, Closure $build): void {
    expect(htmlOf(mailFor($build(), User::factory()->create())))->toContain('abaikan email ini');
})->with('transactional mail');

test('the verification mail states the real validity of its link', function (): void {
    $this->freezeTime();
    config(['auth.verification.expire' => 15]);
    $mail = mailFor(new VerifyEmailNotification, User::factory()->unverified()->create());

    parse_str((string) parse_url($mail->actionUrl, PHP_URL_QUERY), $query);

    expect(implode(' ', $mail->outroLines))->toContain('15 menit')
        ->and((int) $query['expires'])->toBe(now()->addMinutes(15)->getTimestamp());
});

test('the reset mail states the real validity of its token', function (): void {
    config(['auth.passwords.users.expire' => 15]);
    $user = User::factory()->create();

    expect(implode(' ', mailFor(new ResetPasswordNotification('x'), $user)->outroLines))->toContain('15 menit');

    $token = Password::broker()->createToken($user);

    $this->travel(14)->minutes();
    expect(Password::broker()->tokenExists($user, $token))->toBeTrue();

    $this->travel(2)->minutes();
    expect(Password::broker()->tokenExists($user, $token))->toBeFalse();
});

test('the email change mail states the real validity of its link', function (): void {
    $this->freezeTime();
    config(['auth.verification.expire' => 15]);
    NotificationFacade::fake();
    $user = User::factory()->create(['password' => 'current-password']);

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.auth.email.change'), ['email' => 'baru@example.test', 'current_password' => 'current-password'])
        ->assertSuccessful();

    NotificationFacade::assertSentOnDemand(ConfirmEmailChangeNotification::class, function (ConfirmEmailChangeNotification $notification) use ($user): bool {
        parse_str((string) parse_url($notification->url, PHP_URL_QUERY), $query);

        expect(implode(' ', mailFor($notification, $user)->outroLines))->toContain('15 menit')
            ->and((int) $query['expires'])->toBe(now()->addMinutes(15)->getTimestamp());

        return true;
    });
});

test('a name chosen by a stranger cannot put a link in someone\'s inbox', function (): void {
    $user = User::factory()->create(['name' => '[Klik di sini](https://evil.example) <b>x</b>']);

    $html = htmlOf(mailFor(new VerifyEmailNotification, $user));

    expect($html)->not->toContain('href="https://evil.example"')
        ->and($html)->not->toContain('<b>x</b>')
        ->and($html)->toContain('Klik di sini');
});
