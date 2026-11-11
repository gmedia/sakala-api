<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Notifications\Messages\SakalaMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/** Sent to the new address; the change applies only after this link is opened. */
final class ConfirmEmailChangeNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        public readonly string $name,
        public readonly string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): SakalaMailMessage
    {
        $minutes = (int) config('auth.verification.expire', 60);
        $validity = "Tautan ini berlaku {$minutes} menit";

        // The signed link carries its own expiry, so the clock time is exact
        // even when the queue sends this later.
        parse_str((string) parse_url($this->url, PHP_URL_QUERY), $query);
        $expires = $query['expires'] ?? null;

        if (is_string($expires) && ctype_digit($expires) && (int) $expires > now()->getTimestamp()) {
            $validity .= ', sampai '.SakalaMailMessage::wib(Carbon::createFromTimestamp((int) $expires));
        }

        return (new SakalaMailMessage)
            ->subject('Konfirmasi email baru untuk akun Sakala')
            ->preheader('Email akunmu baru berganti setelah kamu membuka tautan konfirmasi di email ini.')
            ->headline('Konfirmasi email baru kamu')
            ->greeting("Halo {$this->name},")
            ->line('Klik tombol di bawah untuk memakai alamat ini sebagai email akun Sakala kamu.')
            ->action('Konfirmasi email baru', $this->url)
            ->line("{$validity}. Email akunmu tidak berubah sebelum tautan ini dibuka.")
            ->line('Kalau bukan kamu yang meminta, abaikan email ini.')
            ->reason('Kamu menerima email ini karena ada permintaan memakai alamat ini sebagai email akun Sakala.');
    }
}
