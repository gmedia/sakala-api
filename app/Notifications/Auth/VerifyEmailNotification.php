<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Notifications\Messages\SakalaMailMessage;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

final class VerifyEmailNotification extends VerifyEmail implements ShouldQueueAfterCommit
{
    use Queueable;

    /** Set while the mail is built, so the text states the expiry the link carries. */
    private ?Carbon $expiresAt = null;

    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            $this->expiresAt ?? now()->addMinutes($this->expirationMinutes()),
            [
                'user' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );
    }

    public function toMail($notifiable): SakalaMailMessage
    {
        $minutes = $this->expirationMinutes();
        $this->expiresAt = now()->addMinutes($minutes);
        $until = SakalaMailMessage::wib($this->expiresAt);

        return (new SakalaMailMessage)
            ->subject('Verifikasi email akun Sakala kamu')
            ->preheader("Klik tautannya sebelum {$until} supaya akunmu bisa dipakai masuk.")
            ->headline('Verifikasi email kamu')
            ->greeting("Halo {$notifiable->name},")
            ->line('Klik tombol di bawah untuk memverifikasi email ini. Setelah itu kamu bisa masuk ke Sakala.')
            ->action('Verifikasi email', $this->verificationUrl($notifiable))
            ->line("Tautan ini berlaku {$minutes} menit, sampai {$until}. Kalau sudah lewat, minta tautan baru dari halaman login.")
            ->line('Kalau kamu tidak merasa mendaftar di Sakala, abaikan email ini.')
            ->reason('Kamu menerima email ini karena alamat ini dipakai untuk mendaftar di Sakala.');
    }

    private function expirationMinutes(): int
    {
        return (int) config('auth.verification.expire', 60);
    }
}
