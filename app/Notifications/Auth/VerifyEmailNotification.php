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
            ->subject('Verifikasi alamat email Sakala')
            ->greeting("Halo {$notifiable->name},")
            ->line('Silakan klik tombol berikut untuk memverifikasi alamat email Anda.')
            ->action('Verifikasi alamat email', $this->verificationUrl($notifiable))
            ->line("Link verifikasi ini berlaku selama {$expiration} menit.")
            ->line('Jika Anda tidak membuat akun Sakala, Anda dapat mengabaikan email ini.');
    }
}
