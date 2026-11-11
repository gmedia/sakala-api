<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Notifications\Messages\SakalaMailMessage;
use App\Services\Auth\ConsoleAuthenticationRedirect;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
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

    public function toMail(object $notifiable): SakalaMailMessage
    {
        $message = (new SakalaMailMessage)->greeting("Halo {$this->name},");

        if ($this->applied) {
            $contact = (string) config('sakala.contact.privacy_email');

            return $message
                ->subject('Email akun Sakala kamu sudah diganti')
                ->preheader("Mulai sekarang kamu masuk ke Sakala dengan {$this->newEmail}.")
                ->headline('Email akunmu sudah diganti')
                ->line("Mulai sekarang kamu masuk ke Sakala dengan {$this->newEmail}. Alamat ini, tempat email ini dikirim, tidak bisa dipakai lagi untuk masuk.")
                ->line('Kalau kamu yang menggantinya, abaikan email ini.')
                ->line("Kalau bukan kamu, segera kirim email ke {$contact} dari alamat ini. Reset kata sandi sekarang hanya bisa lewat email yang baru, jadi kami perlu membantumu memulihkan akun.")
                ->reason('Kamu menerima email ini karena alamat ini sebelumnya dipakai sebagai email akun Sakala.');
        }

        return $message
            ->subject('Ada permintaan ganti email akun Sakala')
            ->preheader('Email akunmu belum berubah. Kalau bukan kamu yang meminta, reset kata sandimu sekarang.')
            ->headline('Ada permintaan ganti email')
            ->line("Ada permintaan untuk mengganti email akun Sakala kamu menjadi {$this->newEmail}. Email akunmu belum berubah sampai tautan konfirmasi di alamat baru itu dibuka.")
            ->line('Kalau kamu yang meminta, abaikan email ini. Kalau bukan kamu, segera reset kata sandi lewat tombol di bawah supaya orang lain tidak bisa masuk ke akunmu.')
            ->action('Reset kata sandi', app(ConsoleAuthenticationRedirect::class)->forgotPassword())
            ->reason('Kamu menerima email ini karena alamat ini adalah email akun Sakala kamu saat ini.');
    }
}
