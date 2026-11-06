<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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

    public function toMail(object $notifiable): MailMessage
    {
        $expiration = (int) config('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject('Konfirmasi email baru akun Sakala')
            ->greeting("Halo {$this->name},")
            ->line('Klik tombol berikut untuk memakai alamat ini sebagai email akun Sakala kamu.')
            ->action('Konfirmasi email baru', $this->url)
            ->line("Tautan ini berlaku selama {$expiration} menit. Email akun tidak berubah sebelum tautan ini dibuka.")
            ->line('Kalau kamu tidak memintanya, abaikan email ini.');
    }
}
