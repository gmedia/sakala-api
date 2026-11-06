<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Services\Auth\ConsoleAuthenticationRedirect;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;

final class ResetPasswordNotification extends ResetPassword implements ShouldQueueAfterCommit
{
    use Queueable;

    protected function resetUrl($notifiable): string
    {
        return app(ConsoleAuthenticationRedirect::class)->passwordReset(
            $this->token,
            $notifiable->getEmailForPasswordReset(),
        );
    }

    public function toMail($notifiable): MailMessage
    {
        $expiration = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset kata sandi Sakala')
            ->greeting("Halo {$notifiable->name},")
            ->line('Kami menerima permintaan untuk mereset kata sandi akun Sakala kamu.')
            ->action('Reset kata sandi', $this->resetUrl($notifiable))
            ->line("Tautan ini berlaku selama {$expiration} menit.")
            ->line('Kalau kamu tidak memintanya, abaikan email ini. Kata sandimu tidak berubah.');
    }
}
