<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Services\Auth\ConsoleAuthenticationRedirect;
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
        $message = (new MailMessage)->greeting("Halo {$this->name},");

        if ($this->applied) {
            return $message
                ->subject('Email akun Sakala sudah diganti')
                ->line("Email akun Sakala kamu sudah diganti menjadi {$this->newEmail}. Alamat lama, tempat email ini dikirim, tidak bisa dipakai lagi untuk masuk.")
                ->line('Kalau kamu yang menggantinya, abaikan email ini. Kalau bukan kamu, segera hubungi admin Sakala, karena reset kata sandi sekarang hanya bisa lewat email yang baru.');
        }

        return $message
            ->subject('Permintaan ganti email akun Sakala')
            ->line("Ada permintaan untuk mengganti email akun Sakala kamu menjadi {$this->newEmail}. Email akunmu belum berubah sampai tautan konfirmasi di alamat baru dibuka.")
            ->line('Kalau kamu yang meminta, abaikan email ini. Kalau bukan kamu, segera reset kata sandi lewat tombol di bawah.')
            ->action('Reset kata sandi', app(ConsoleAuthenticationRedirect::class)->forgotPassword());
    }
}
