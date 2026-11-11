<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Notifications\Messages\SakalaMailMessage;
use App\Services\Auth\ConsoleAuthenticationRedirect;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

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

    /**
     * No clock time here: the token's age counts from the request, and a queued
     * mail can leave later than that.
     */
    public function toMail($notifiable): SakalaMailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new SakalaMailMessage)
            ->subject('Permintaan reset kata sandi akun Sakala')
            ->preheader("Buat kata sandi baru lewat tautan yang berlaku {$minutes} menit.")
            ->headline('Buat kata sandi baru')
            ->greeting("Halo {$notifiable->name},")
            ->line('Klik tombol di bawah untuk membuat kata sandi baru untuk akun Sakala kamu.')
            ->action('Buat kata sandi baru', $this->resetUrl($notifiable))
            ->line("Tautan ini berlaku {$minutes} menit sejak permintaan dibuat dan hanya bisa dipakai sekali.")
            ->line('Kalau bukan kamu yang meminta, abaikan email ini. Kata sandimu tidak berubah selama tautan ini tidak dipakai.')
            ->reason('Kamu menerima email ini karena ada permintaan reset kata sandi untuk akun Sakala dengan alamat ini.');
    }
}
