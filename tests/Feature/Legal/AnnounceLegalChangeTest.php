<?php

declare(strict_types=1);

use App\Actions\Auth\DeleteAccountAction;
use App\Enums\LegalDocument;
use App\Models\LegalChangeAnnouncement;
use App\Models\User;
use App\Notifications\Legal\LegalDocumentChangedNotification;
use App\Support\Legal\LegalChangeCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\PendingCommand;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config()->set('sakala.email_delivery_enabled', true);
    config()->set('sakala.legal.versions.privacy_notice', 'privasi-v2');
    config()->set('sakala.legal.effective_at.privacy_notice', '2026-11-30T00:00:00+07:00');
    config()->set('sakala.legal.previous_versions.privacy_notice', 'privasi-v1');
    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:00:00', 'Asia/Jakarta'));
    Notification::fake();
});

function announce(array $options = []): PendingCommand
{
    return test()->artisan('legal:announce-change', ['version' => 'privasi-v2', ...$options]);
}

test('a dry run counts the verified accounts and queues nothing', function (): void {
    User::factory()->count(3)->create();
    User::factory()->unverified()->create();

    announce(['--dry-run' => true])
        ->expectsOutputToContain('Dry run: nothing was queued.')
        ->assertSuccessful();

    Notification::assertNothingSent();
    expect(LegalChangeAnnouncement::query()->count())->toBe(0);
});

test('a dry run prints counts, never an address', function (): void {
    User::factory()->create(['email' => 'rani@example.test']);

    announce(['--dry-run' => true])
        ->doesntExpectOutputToContain('rani@example.test')
        ->expectsTable(
            ['Version', 'Effective', 'Already announced', 'Waiting', 'Would queue'],
            [['privasi-v2', '30 November 2026 pukul 00.00 WIB', '0', '1', '1']],
        )
        ->assertSuccessful();
});

test('every verified account gets the email once, however often it runs', function (): void {
    $verified = User::factory()->count(2)->create();
    $unverified = User::factory()->unverified()->create();

    announce()->assertSuccessful();
    announce()->assertSuccessful();

    foreach ($verified as $user) {
        Notification::assertSentToTimes($user, LegalDocumentChangedNotification::class, 1);
    }

    Notification::assertNotSentTo($unverified, LegalDocumentChangedNotification::class);

    $rows = LegalChangeAnnouncement::query()->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('user_id')->sort()->values()->all())->toBe($verified->pluck('id')->sort()->values()->all())
        ->and($rows->every(fn (LegalChangeAnnouncement $row): bool => $row->document === LegalDocument::PrivacyNotice && $row->version === 'privasi-v2'))->toBeTrue();
});

test('an account verified after the first run gets it on the next', function (): void {
    User::factory()->create();
    $late = User::factory()->unverified()->create();

    announce()->assertSuccessful();
    Notification::assertNotSentTo($late, LegalDocumentChangedNotification::class);

    $late->forceFill(['email_verified_at' => now()])->save();
    announce()->assertSuccessful();

    Notification::assertSentToTimes($late, LegalDocumentChangedNotification::class, 1);
});

test('the limit splits sending over several runs without repeats', function (): void {
    $users = User::factory()->count(5)->create();

    announce(['--limit' => 2])->assertSuccessful();
    expect(LegalChangeAnnouncement::query()->count())->toBe(2);

    announce(['--limit' => 2])->assertSuccessful();
    announce(['--limit' => 2])->assertSuccessful();
    announce(['--limit' => 2])->assertSuccessful();

    foreach ($users as $user) {
        Notification::assertSentToTimes($user, LegalDocumentChangedNotification::class, 1);
    }

    expect(LegalChangeAnnouncement::query()->count())->toBe(5);
});

test('the emails are spaced by the interval', function (): void {
    $users = User::factory()->count(3)->create();
    $delays = [];

    announce(['--interval' => 5])->assertSuccessful();

    Notification::assertSentTimes(LegalDocumentChangedNotification::class, 3);

    foreach ($users as $user) {
        Notification::assertSentTo($user, LegalDocumentChangedNotification::class, function (LegalDocumentChangedNotification $notification) use (&$delays): bool {
            $delays[] = (int) round(now()->diffInSeconds($notification->delay));

            return true;
        });
    }

    sort($delays);
    expect($delays)->toBe([0, 5, 10]);
});

test('the email carries the effective date and the summary', function (): void {
    $user = User::factory()->create();

    announce()->assertSuccessful();

    Notification::assertSentTo($user, LegalDocumentChangedNotification::class, function (LegalDocumentChangedNotification $notification) use ($user): bool {
        $mail = $notification->toMail($user);

        return $notification->effectiveAt->eq(CarbonImmutable::parse('2026-11-30 00:00:00', 'Asia/Jakarta'))
            && $mail->subject === 'Pemberitahuan Privasi Sakala berubah mulai 30 November 2026'
            && $mail->actionUrl === rtrim((string) config('sakala.landing_url'), '/').'/privasi'
            && in_array('Yang berubah:', $mail->introLines, true);
    });
});

test('a matching --effective is accepted', function (): void {
    User::factory()->create();

    announce(['--effective' => '2026-11-30', '--dry-run' => true])->assertSuccessful();
});

test('it refuses what would misstate the change', function (array $options, ?Closure $arrange, string $message): void {
    User::factory()->create();
    $arrange?->__invoke();

    $this->artisan('legal:announce-change', ['version' => 'privasi-v2', ...$options])
        ->expectsOutputToContain($message)
        ->assertFailed();

    Notification::assertNothingSent();
    expect(LegalChangeAnnouncement::query()->count())->toBe(0);
})->with([
    'a version without a summary' => [['version' => 'privasi-v9'], null, 'No summary for privasi-v9'],
    'a version the API does not publish yet' => [[], fn () => config()->set('sakala.legal.versions.privacy_notice', 'privasi-v1'), 'The API publishes privasi-v1'],
    'another effective date than configured' => [['--effective' => '2026-12-01'], null, 'differs from the configured'],
    'a date that is not a date' => [['--effective' => '30-11-2026'], null, '--effective takes a date as YYYY-MM-DD.'],
    'after the version took effect' => [[], fn () => test()->travelTo(CarbonImmutable::parse('2026-11-30 00:00:00', 'Asia/Jakarta')), 'Changes are announced before they take effect.'],
    'no date anywhere' => [[], fn () => config()->set('sakala.legal.effective_at.privacy_notice', ''), 'No effective date is configured'],
    'a negative limit' => [['--limit' => '-1'], null, '--limit and --interval take a whole number'],
    'email delivery off' => [[], fn () => config()->set('sakala.email_delivery_enabled', false), 'Email delivery is off'],
]);

test('a dry run works with email delivery off', function (): void {
    config()->set('sakala.email_delivery_enabled', false);
    User::factory()->create();

    announce(['--dry-run' => true])->assertSuccessful();
});

test('a deleted account takes its record with it', function (): void {
    $user = User::factory()->create();

    announce()->assertSuccessful();
    app(DeleteAccountAction::class)->handle($user);

    expect(LegalChangeAnnouncement::query()->count())->toBe(0);
});

test('an email that cannot be queued leaves no record, so the next run tries again', function (): void {
    $user = User::factory()->create();
    $this->instance(Dispatcher::class, Mockery::mock(Dispatcher::class, function ($mock): void {
        $mock->shouldReceive('send')->andThrow(new RuntimeException('queue unavailable'));
    }));

    expect(fn () => $this->artisan('legal:announce-change', ['version' => 'privasi-v2'])->run())
        ->toThrow(RuntimeException::class, 'queue unavailable');
    expect(LegalChangeAnnouncement::query()->count())->toBe(0);

    $this->instance(Dispatcher::class, Notification::fake());

    announce()->assertSuccessful();
    Notification::assertSentToTimes($user, LegalDocumentChangedNotification::class, 1);
});

// ─── privasi-v3 ──────────────────────────────────────────────────────────────

test('privasi-v3 has a summary that follows privasi-v2', function (): void {
    $change = app(LegalChangeCatalog::class)->find('privasi-v3');

    expect($change)->not->toBeNull()
        ->and($change->document)->toBe(LegalDocument::PrivacyNotice)
        ->and($change->version)->toBe('privasi-v3')
        ->and($change->previousVersion)->toBe('privasi-v2')
        ->and($change->changes)->toHaveCount(4)
        ->and(collect($change->changes)->every(fn (string $line): bool => trim($line) !== ''))->toBeTrue()
        ->and(app(LegalChangeCatalog::class)->find('privasi-v2'))->not->toBeNull();
});

test('privasi-v3 is announced before 7 December 2026 WIB, and refused from then', function (): void {
    config()->set('sakala.legal.versions.privacy_notice', 'privasi-v3');
    config()->set('sakala.legal.effective_at.privacy_notice', '2026-12-07T00:00:00+07:00');
    config()->set('sakala.legal.previous_versions.privacy_notice', 'privasi-v2');
    User::factory()->create();

    $this->travelTo(CarbonImmutable::parse('2026-12-06 23:59:59', 'Asia/Jakarta'));

    $this->artisan('legal:announce-change', ['version' => 'privasi-v3', '--dry-run' => true])
        ->expectsTable(
            ['Version', 'Effective', 'Already announced', 'Waiting', 'Would queue'],
            [['privasi-v3', '7 Desember 2026 pukul 00.00 WIB', '0', '1', '1']],
        )
        ->assertSuccessful();

    $this->travelTo(CarbonImmutable::parse('2026-12-07 00:00:00', 'Asia/Jakarta'));

    $this->artisan('legal:announce-change', ['version' => 'privasi-v3', '--dry-run' => true])
        ->expectsOutputToContain('Changes are announced before they take effect.')
        ->assertFailed();

    Notification::assertNothingSent();
    expect(LegalChangeAnnouncement::query()->count())->toBe(0);
});
