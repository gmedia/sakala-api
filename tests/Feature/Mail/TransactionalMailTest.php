<?php

declare(strict_types=1);

use App\Actions\Auth\DeleteAccountAction;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Notifications\Auth\ConfirmEmailChangeNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Notifications\Messages\SakalaMailMessage;
use App\Support\Mail\MailPreviewCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

uses(RefreshDatabase::class);

const MAIL_CONSOLE = 'https://console.example.test';
const MAIL_API = 'https://api.example.test';
const MAIL_SITE = 'https://site.example.test';
const MAIL_LOGO = 'https://cdn.example.test/email/sakala-logo@2x.png';

beforeEach(function (): void {
    // The real templates must not depend on either name being right.
    config([
        'app.name' => 'Laravel',
        'app.url' => MAIL_API,
        'mail.default' => 'array',
        'sakala.console_url' => MAIL_CONSOLE,
        'sakala.landing_url' => MAIL_SITE,
        'sakala.mail_logo_url' => MAIL_LOGO,
        'sakala.email_delivery_enabled' => true,
        'sakala.rate_limits.register' => 100,
    ]);
    URL::forceRootUrl(MAIL_API);
    URL::forceScheme('https');
});

/** @return list<Email> Everything the array mailer delivered so far. */
function deliveredMail(): array
{
    return collect(Mail::mailer('array')->getSymfonyTransport()->messages())
        ->map(function (SentMessage $sent): Email {
            $message = $sent->getOriginalMessage();
            assert($message instanceof Email);

            return $message;
        })
        ->values()
        ->all();
}

function onlyMailTo(string $address): Email
{
    $matching = array_values(array_filter(
        deliveredMail(),
        fn (Email $email): bool => array_map(fn (Address $to): string => $to->getAddress(), $email->getTo()) === [$address],
    ));

    expect($matching)->toHaveCount(1, "exactly one email to {$address}");

    return $matching[0];
}

/** The href of the primary button, or null when the mail has none. */
function primaryLink(Email $email): ?string
{
    preg_match_all('/<a href="([^"]+)" class="button\b/', (string) $email->getHtmlBody(), $matches);

    expect(count($matches[1]))->toBeLessThanOrEqual(1);

    return isset($matches[1][0]) ? html_entity_decode($matches[1][0], ENT_QUOTES | ENT_HTML5) : null;
}

/**
 * The shared checks: a text part that stands alone, the layout's footer, and no
 * credential besides the one link this mail exists to deliver.
 *
 * @param  list<string>  $secrets  Values that must never appear (passwords, hashes, other tokens).
 */
function expectWellFormedMail(Email $email, ?string $link, array $secrets = []): void
{
    $html = (string) $email->getHtmlBody();
    $text = (string) $email->getTextBody();

    expect($text)->not->toBe('')
        ->and($text)->not->toContain('<')
        ->and($text)->not->toContain('](')
        ->and($text)->toContain('Sakala Maintainers')
        ->and($text)->toContain(MAIL_SITE.'/privasi')
        ->and($text)->toContain(MAIL_SITE.'/syarat')
        ->and($html)->toContain('href="'.MAIL_SITE.'/privasi"')
        ->and($html)->toContain('href="'.MAIL_SITE.'/syarat"')
        ->and($html)->toContain('Kamu menerima email ini karena')
        // The logo is the only image: no tracking pixel, nothing else to load.
        ->and(substr_count($html, '<img'))->toBe(1)
        ->and(preg_match_all('/\ssrc="([^"]*)"/', $html, $sources))->toBe(1)
        ->and($sources[1])->toBe([MAIL_LOGO]);

    if ($link !== null) {
        expect($text)->toContain($link)
            ->and(substr_count($html, 'href="'.htmlspecialchars($link, ENT_NOQUOTES).'"'))->toBe(3, 'the button, its Outlook twin, and the fallback link');
    }

    // Take the intended link out; what is left must hold no credential at all.
    $rest = implode("\n", [$html, $text, (string) $email->getSubject()]);

    if ($link !== null) {
        $rest = str_replace([$link, htmlspecialchars($link, ENT_NOQUOTES), htmlspecialchars($link)], '', $rest);
    }

    expect($rest)->not->toMatch('/token=|signature=|expires=/')
        // Hex, base64, and random tokens; dashes would match ordinary words and UUIDs.
        ->and($rest)->not->toMatch('/[A-Za-z0-9+_]{32,}/')
        ->and($rest)->not->toContain((string) config('app.key'));

    foreach ($secrets as $secret) {
        expect($rest)->not->toContain($secret);
    }
}

function registrationPayload(string $email, string $name = 'Rani Lestari'): array
{
    return [
        'name' => $name,
        'email' => $email,
        'password' => 'kata-sandi-rahasia-123',
        'password_confirmation' => 'kata-sandi-rahasia-123',
        'accept_terms' => true,
        'terms_version' => 'syarat-v1',
        'acceptable_use_version' => 'penggunaan-wajar-v1',
    ];
}

test('registering sends a verification link that really verifies', function (): void {
    $this->freezeTime();
    $this->postJson(route('api.v1.auth.register'), registrationPayload('rani@example.test'))->assertAccepted();

    $user = User::query()->where('email', 'rani@example.test')->sole();
    $email = onlyMailTo('rani@example.test');
    $link = primaryLink($email);

    expect($email->getSubject())->toBe('Verifikasi email akun Sakala kamu')
        ->and($link)->toStartWith(MAIL_API."/auth/email/verify/{$user->id}/")
        ->and(URL::hasValidSignature(Request::create((string) $link)))->toBeTrue()
        ->and((string) $email->getHtmlBody())->toContain('abaikan email ini');

    expectWellFormedMail($email, $link, ['kata-sandi-rahasia-123', (string) $user->password]);
});

test('registering with a taken email tells the owner to sign in, without a token', function (): void {
    $owner = User::factory()->create(['email' => 'rani@example.test', 'name' => 'Rani Lestari']);

    $this->postJson(route('api.v1.auth.register'), registrationPayload('rani@example.test', 'Orang Lain'))->assertAccepted();

    $email = onlyMailTo('rani@example.test');

    expect($email->getSubject())->toBe('Email kamu sudah terdaftar di Sakala')
        ->and(primaryLink($email))->toBe(MAIL_CONSOLE.'/login')
        ->and((string) $email->getHtmlBody())->not->toContain('Orang Lain');

    expectWellFormedMail($email, MAIL_CONSOLE.'/login', ['kata-sandi-rahasia-123', (string) $owner->password]);
});

test('a forgotten password sends a reset link carrying a valid token', function (): void {
    $user = User::factory()->create(['email' => 'rani+kerja@example.test']);

    $this->postJson(route('api.v1.auth.password.forgot'), ['email' => 'rani+kerja@example.test'])->assertAccepted();

    $email = onlyMailTo('rani+kerja@example.test');
    $link = (string) primaryLink($email);
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    expect($email->getSubject())->toBe('Permintaan reset kata sandi akun Sakala')
        ->and($link)->toStartWith(MAIL_CONSOLE.'/reset-password?token=')
        ->and($query['email'])->toBe('rani+kerja@example.test')
        ->and(Password::broker()->tokenExists($user, (string) $query['token']))->toBeTrue();

    expectWellFormedMail($email, $link, [(string) $user->password]);
});

test('changing the email confirms at the new address, warns the old one, and says when it applied', function (): void {
    $this->freezeTime();
    $user = User::factory()->create(['email' => 'lama@example.test', 'name' => 'Rani Lestari']);

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.auth.email.change'), ['email' => 'baru@example.test', 'current_password' => 'password'])
        ->assertAccepted();

    $confirm = onlyMailTo('baru@example.test');
    $link = (string) primaryLink($confirm);

    expect($confirm->getSubject())->toBe('Konfirmasi email baru untuk akun Sakala')
        ->and($link)->toStartWith(MAIL_API.'/')
        ->and(URL::hasValidSignature(Request::create($link)))->toBeTrue();
    expectWellFormedMail($confirm, $link, [(string) $user->password]);

    $requested = onlyMailTo('lama@example.test');

    expect($requested->getSubject())->toBe('Ada permintaan ganti email akun Sakala')
        ->and(primaryLink($requested))->toBe(MAIL_CONSOLE.'/forgot-password');
    expectWellFormedMail($requested, MAIL_CONSOLE.'/forgot-password', [$link]);

    Mail::mailer('array')->getSymfonyTransport()->flush();
    $this->get($link)->assertRedirect();

    expect($user->fresh()->email)->toBe('baru@example.test');

    $applied = onlyMailTo('lama@example.test');

    expect($applied->getSubject())->toBe('Email akun Sakala kamu sudah diganti')
        ->and(primaryLink($applied))->toBeNull()
        ->and((string) $applied->getHtmlBody())->toContain('baru@example.test')
        ->and((string) $applied->getHtmlBody())->toContain((string) config('sakala.contact.privacy_email'));
    expectWellFormedMail($applied, null, [$link]);
});

test('an invitation goes to the invited address with the only copy of its token', function (): void {
    $owner = User::factory()->create(['name' => 'Budi Santoso']);
    $team = teamWorkspace($owner, attributes: ['name' => 'Tim Facility']);

    $this->actingAs($owner, 'web')
        ->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'rani@example.test', 'role' => 'viewer'])
        ->assertCreated();

    $invitation = WorkspaceInvitation::query()->sole();
    $email = onlyMailTo('rani@example.test');
    $link = (string) primaryLink($email);
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    expect($email->getSubject())->toBe('Budi Santoso mengundangmu ke Tim Facility di Sakala')
        ->and($link)->toStartWith(MAIL_CONSOLE."/workspace-invitations/{$invitation->id}?token=")
        ->and(WorkspaceInvitation::hashToken((string) $query['token']))->toBe($invitation->token_hash)
        ->and((string) $email->getHtmlBody())->toContain('sebagai pengamat')
        ->and((string) $email->getHtmlBody())->toContain(SakalaMailMessage::wib($invitation->expires_at));

    expectWellFormedMail($email, $link, [$invitation->token_hash, (string) $owner->password]);
});

test('a suspension reaches the owner with the reason, the contact, and a link to the project', function (): void {
    $owner = User::factory()->create(['email' => 'rani@example.test']);
    $project = Project::factory()->for($owner)->create(['name' => 'toko-saya']);
    $admin = User::factory()->create(['role' => UserRole::Admin, 'email' => 'admin@example.test']);

    $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", ['reason' => 'Halaman login yang meniru situs bank.'])
        ->assertAccepted();

    $email = onlyMailTo('rani@example.test');
    $html = (string) $email->getHtmlBody();

    expect($email->getSubject())->toBe('Proyek toko-saya di Sakala ditangguhkan')
        ->and(primaryLink($email))->toBe(MAIL_CONSOLE."/projects/{$project->id}")
        ->and($html)->toContain('Halaman login yang meniru situs bank.')
        ->and($html)->toContain((string) config('sakala.contact.abuse_email'))
        ->and($html)->not->toContain('admin@example.test');

    expectWellFormedMail($email, MAIL_CONSOLE."/projects/{$project->id}", [(string) $owner->password]);
});

test('deleting the account confirms it to the old address, with nothing to click', function (): void {
    $user = User::factory()->create(['email' => 'rani@example.test']);

    app(DeleteAccountAction::class)->handle($user);

    $email = onlyMailTo('rani@example.test');

    expect($email->getSubject())->toBe('Akun Sakala kamu sudah dihapus')
        ->and(primaryLink($email))->toBeNull()
        ->and((string) $email->getHtmlBody())->toContain((string) config('sakala.contact.privacy_email'));

    expectWellFormedMail($email, null, [(string) $user->password]);
});

test('every notification with a mail version is in the preview catalog', function (): void {
    $withMail = [];

    foreach (Finder::create()->files()->name('*.php')->in(app_path('Notifications')) as $file) {
        $class = 'App\\Notifications\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (is_subclass_of($class, Notification::class) && method_exists($class, 'toMail')) {
            $withMail[] = $class;
        }
    }

    $cataloged = array_map(
        fn (array $preview): string => $preview['notification']::class,
        app(MailPreviewCatalog::class)->previews(),
    );

    expect(array_values(array_unique($cataloged)))->toEqualCanonicalizing($withMail);
});

// Literal, because datasets are built before the application boots; the test
// below keeps it equal to the catalog.
const MAIL_NAMES = [
    'verify-email', 'reset-password', 'existing-account', 'email-change-confirm', 'email-change-requested',
    'email-change-applied', 'workspace-invitation', 'project-suspended', 'account-deleted',
];

dataset('catalog', MAIL_NAMES);

test('the dataset lists every email in the catalog', function (): void {
    expect(array_keys(app(MailPreviewCatalog::class)->previews()))->toBe(MAIL_NAMES);
});

test('every email uses the shared layout, in Indonesian, with at most one button', function (string $name): void {
    $catalog = app(MailPreviewCatalog::class);
    $preview = $catalog->previews()[$name];
    $mail = $catalog->mail($preview['notification'], $preview['notifiable']);
    ['subject' => $subject, 'html' => $html, 'text' => $text] = $catalog->render($mail);
    $buttons = substr_count($html, 'class="button ');

    expect($mail)->toBeInstanceOf(SakalaMailMessage::class)
        ->and($subject)->toContain('Sakala')
        ->and($html)->toContain('<html xmlns="http://www.w3.org/1999/xhtml"')
        ->and($html)->toContain('lang="id"')
        ->and($html)->toContain('<meta name="color-scheme" content="light dark">')
        ->and($html)->toContain('<title>'.e($subject).'</title>')
        ->and($html)->toContain('class="preheader"')
        ->and($html)->toContain('<h1')
        ->and($html)->toContain('<a href="'.MAIL_CONSOLE.'" class="wordmark"')
        ->and($html)->toMatch('#<img src="'.preg_quote(MAIL_LOGO, '#').'" width="140" height="59" alt="Sakala" class="logo" style="([^"]*)"#')
        ->and($html)->toContain('PT Media Sarana Data (GMEDIA)')
        ->and($buttons)->toBeLessThanOrEqual(1)
        ->and(substr_count($html, '<v:roundrect'))->toBe($buttons, 'Outlook gets the same button')
        ->and(strlen($html))->toBeLessThan(100_000, 'Gmail clips messages over 102 KB');

    foreach ([$subject, $html, $text] as $part) {
        expect($part)
            ->not->toContain('Laravel')
            ->not->toContain('Sakala API')
            ->not->toContain('Regards')
            ->not->toContain('All rights reserved')
            ->not->toContain('If you')
            ->not->toMatch('/\bAnda\b/');
    }

    // No sentence shouts: a run of capitals longer than an acronym.
    expect(strip_tags($html))->not->toMatch('/\b[A-Z]{7,}\b/')
        ->and($text)->not->toMatch('/\b[A-Z]{7,}\b/');
})->with('catalog');

test('the logo stays readable when images are blocked: the alt text is the teal wordmark', function (): void {
    $catalog = app(MailPreviewCatalog::class);
    $preview = $catalog->previews()['verify-email'];
    $html = $catalog->render($catalog->mail($preview['notification'], $preview['notifiable']))['html'];

    expect(preg_match('#<img src="[^"]*" width="140" height="59" alt="Sakala" class="logo" style="([^"]*)"#', $html, $match))->toBe(1);

    $style = str_replace(' ', '', $match[1]);

    expect($style)->toContain('display:block')
        ->and($style)->toContain('border:0')
        ->and($style)->toContain('color:#0f766e')
        ->and($style)->toContain('font-size:24px')
        // Dark mode keeps the fallback readable.
        ->and($html)->toContain('.logo { color: #5fbfb5 !important; }')
        ->and($html)->toContain('[data-ogsc] .logo { color: #5fbfb5 !important; }');
});

test('the logo comes from sakala.dev unless the install points elsewhere', function (): void {
    $defaults = require config_path('sakala.php');

    expect($defaults['mail_logo_url'])->toBe('https://sakala.dev/email/sakala-logo@2x.png');

    config(['sakala.mail_logo_url' => 'https://mail.example.org/brand/logo.png']);
    $catalog = app(MailPreviewCatalog::class);
    $preview = $catalog->previews()['account-deleted'];
    $html = $catalog->render($catalog->mail($preview['notification'], $preview['notifiable']))['html'];

    expect($html)->toContain('<img src="https://mail.example.org/brand/logo.png" width="140" height="59" alt="Sakala"')
        ->and($html)->not->toContain('sakala.dev/email');
});

test('mail:preview writes every email to files and sends nothing', function (): void {
    NotificationFacade::fake();
    $path = sys_get_temp_dir().'/sakala-mail-preview-'.bin2hex(random_bytes(4));

    $this->artisan('mail:preview', ['--path' => $path])->assertSuccessful();

    foreach (array_keys(app(MailPreviewCatalog::class)->previews()) as $name) {
        expect("{$path}/{$name}.html")->toBeFile()
            ->and((string) file_get_contents("{$path}/{$name}.txt"))->toStartWith('Subject: ');
    }

    expect(deliveredMail())->toBe([]);
    NotificationFacade::assertNothingSent();

    array_map('unlink', glob("{$path}/*") ?: []);
    rmdir($path);
});

test('mail:preview refuses to run in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    $path = sys_get_temp_dir().'/sakala-mail-preview-never-'.bin2hex(random_bytes(4));

    $this->artisan('mail:preview', ['--path' => $path])->assertFailed();

    expect($path)->not->toBeDirectory();
});

test('the verification mail states the real validity of its link, in WIB', function (): void {
    $this->travelTo('2026-11-12 03:00:00');
    config(['auth.verification.expire' => 15]);
    $mail = (new VerifyEmailNotification)->toMail(User::factory()->unverified()->create());

    parse_str((string) parse_url($mail->actionUrl, PHP_URL_QUERY), $query);

    expect(implode(' ', $mail->outroLines))->toContain('15 menit, sampai 12 November 2026 pukul 10.15 WIB')
        ->and((int) $query['expires'])->toBe(now()->addMinutes(15)->getTimestamp());
});

test('the reset mail states the real validity of its token', function (): void {
    config(['auth.passwords.users.expire' => 15]);
    $user = User::factory()->create();

    expect(implode(' ', (new ResetPasswordNotification('x'))->toMail($user)->outroLines))->toContain('15 menit');

    $token = Password::broker()->createToken($user);

    $this->travel(14)->minutes();
    expect(Password::broker()->tokenExists($user, $token))->toBeTrue();

    $this->travel(2)->minutes();
    expect(Password::broker()->tokenExists($user, $token))->toBeFalse();
});

test('the email change mail states the expiry its signed link carries', function (): void {
    $this->travelTo('2026-11-12 03:00:00');
    config(['auth.verification.expire' => 15]);
    NotificationFacade::fake();
    $user = User::factory()->create(['password' => 'current-password']);

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.auth.email.change'), ['email' => 'baru@example.test', 'current_password' => 'current-password'])
        ->assertSuccessful();

    NotificationFacade::assertSentOnDemand(ConfirmEmailChangeNotification::class, function (ConfirmEmailChangeNotification $notification) use ($user): bool {
        parse_str((string) parse_url($notification->url, PHP_URL_QUERY), $query);

        expect(implode(' ', $notification->toMail($user)->outroLines))->toContain('15 menit, sampai 12 November 2026 pukul 10.15 WIB')
            ->and((int) $query['expires'])->toBe(now()->addMinutes(15)->getTimestamp());

        return true;
    });
});

test('a name chosen by a stranger cannot put a link in someone\'s inbox', function (): void {
    $user = User::factory()->create(['name' => '[Klik di sini](https://evil.example) <b>x</b>']);
    $catalog = app(MailPreviewCatalog::class);

    $html = $catalog->render((new VerifyEmailNotification)->toMail($user))['html'];

    expect($html)->not->toContain('href="https://evil.example"')
        ->and($html)->not->toContain('<b>x</b>')
        ->and($html)->toContain('Klik di sini');
});
