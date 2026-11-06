<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the old address, when the change is requested and when it applies. */
final class EmailChangeNoticeNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        public readonly string $name,
        public readonly string $newEmail,
        public readonly bool $applied,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->applied ? 'Email akun Sakala sudah diganti' : 'Permintaan ganti email akun Sakala')
            ->greeting("Halo {$this->name},")
            ->line($this->applied
                ? "Email akun Sakala kamu sudah diganti menjadi {$this->newEmail}."
                : "Ada permintaan untuk mengganti email akun Sakala kamu menjadi {$this->newEmail}. Email belum berubah sampai tautan konfirmasi di alamat baru dibuka.")
            ->line('Kalau ini bukan kamu, segera reset kata sandi dan hubungi admin Sakala.');
    }
}
