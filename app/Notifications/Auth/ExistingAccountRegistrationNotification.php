<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Models\User;
use App\Notifications\Messages\SakalaMailMessage;
use App\Services\Auth\ConsoleAuthenticationRedirect;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
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

    public function toMail(User $notifiable): SakalaMailMessage
    {
        return (new SakalaMailMessage)
            ->subject('Email kamu sudah terdaftar di Sakala')
            ->preheader('Kalau tadi kamu mencoba mendaftar, masuk saja dengan akun yang sudah ada.')
            ->headline('Kamu sudah punya akun Sakala')
            ->greeting("Halo {$notifiable->name},")
            ->line('Masuk dengan akun yang sudah ada lewat tombol di bawah. Baru saja ada yang mencoba mendaftar di Sakala dengan email ini, padahal akunnya sudah ada.')
            ->action('Masuk ke Sakala', app(ConsoleAuthenticationRedirect::class)->login())
            ->line('Lupa kata sandi? Kamu bisa meresetnya dari halaman login.')
            ->line('Kalau bukan kamu yang mencoba mendaftar, abaikan email ini. Akunmu tidak berubah dan tidak ada akun baru yang dibuat.')
            ->reason('Kamu menerima email ini karena alamat ini dimasukkan ke formulir pendaftaran Sakala.');
    }
}
