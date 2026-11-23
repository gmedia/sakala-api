<?php

declare(strict_types=1);

namespace App\Notifications\Legal;

use App\Data\Legal\LegalChangeData;
use App\Notifications\Messages\SakalaMailMessage;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Announces a new version of a legal document before it takes effect. It goes
 * to every verified account whatever its notification settings, because the
 * documents promise to announce meaningful changes by account email.
 *
 * Queued inside the transaction that records it (not after commit), so a
 * failure to queue also undoes the record and a later run tries again.
 */
final class LegalDocumentChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** An account deleted before its turn is simply skipped. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly LegalChangeData $change,
        public readonly CarbonImmutable $effectiveAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): SakalaMailMessage
    {
        $title = $this->change->document->title();
        $date = self::date($this->effectiveAt);
        $url = rtrim((string) config('sakala.landing_url'), '/').$this->change->document->path();
        $contact = (string) config('sakala.contact.privacy_email');
        $message = new SakalaMailMessage;

        if (is_string($name = data_get($notifiable, 'name')) && $name !== '') {
            $message->greeting("Halo {$name},");
        }

        $message
            ->subject("{$title} Sakala berubah mulai {$date}")
            ->preheader("Versi {$this->change->version} berlaku mulai {$date}. Kamu tidak perlu melakukan apa pun.")
            ->headline("{$title} berubah mulai {$date}")
            ->line("Kami memperbarui {$title} Sakala. Versi baru, {$this->change->version}, berlaku mulai {$date}. Sampai tanggal itu, yang berlaku masih {$this->change->previousVersion}.")
            ->line('Yang berubah:');

        foreach ($this->change->changes as $change) {
            $message->line("- {$change}");
        }

        return $message
            ->line('Kamu tidak perlu melakukan apa pun, dan perubahan ini tidak meminta persetujuanmu.')
            ->action("Baca {$title}", $url)
            ->line("Kalau ada pertanyaan, kirim email ke {$contact}.")
            ->reason('Kamu menerima email ini karena kamu punya akun Sakala. Perubahan dokumen hukum Sakala selalu diumumkan lewat email akun, apa pun pengaturan notifikasimu.');
    }

    /** For example "30 November 2026", in WIB. */
    private static function date(CarbonImmutable $time): string
    {
        $local = Carbon::instance($time)->setTimezone(SakalaMailMessage::TIMEZONE);
        // Sets the locale on this instance only, whatever the app locale is.
        $local->locale('id');

        return $local->translatedFormat('j F Y');
    }
}
