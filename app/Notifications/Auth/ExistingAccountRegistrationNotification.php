<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Models\User;
use App\Services\Auth\ConsoleAuthenticationRedirect;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Told to the owner instead of the person registering, so the API reveals nothing. */
final class ExistingAccountRegistrationNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Email kamu sudah punya akun Sakala')
            ->greeting("Halo {$notifiable->name},")
            ->line('Ada yang mencoba mendaftar di Sakala dengan email ini, padahal akunnya sudah ada.')
            ->line('Kalau itu kamu, masuk saja lewat tombol di bawah. Kalau lupa kata sandi, kamu bisa meresetnya dari halaman login.')
            ->action('Masuk ke Sakala', app(ConsoleAuthenticationRedirect::class)->login())
            ->line('Kalau bukan kamu, abaikan email ini. Akunmu tidak berubah.');
    }
}
