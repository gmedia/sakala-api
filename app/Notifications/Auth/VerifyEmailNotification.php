<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

final class VerifyEmailNotification extends VerifyEmail implements ShouldQueueAfterCommit
{
    use Queueable;

    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'user' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );
    }

    public function toMail($notifiable): MailMessage
    {
        $expiration = (int) config('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject('Verifikasi email akun Sakala')
            ->greeting("Halo {$notifiable->name},")
            ->line('Klik tombol di bawah untuk memverifikasi email kamu. Setelah itu kamu bisa masuk ke Sakala.')
            ->action('Verifikasi email', $this->verificationUrl($notifiable))
            ->line("Tautan ini berlaku selama {$expiration} menit. Kalau sudah lewat, minta tautan baru dari halaman login.")
            ->line('Kalau bukan kamu yang mendaftar, abaikan email ini.');
    }
}
