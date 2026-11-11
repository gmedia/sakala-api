<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use App\Notifications\Messages\SakalaMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

/**
 * Goes to a routed address, because the user it is about no longer exists, so
 * the queue holds the address only until this is sent. No button: there is
 * nothing left to sign in to.
 */
final class AccountDeletedNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): SakalaMailMessage
    {
        $contact = (string) config('sakala.contact.privacy_email');

        return (new SakalaMailMessage)
            ->subject('Akun Sakala kamu sudah dihapus')
            ->preheader('Akunmu tidak bisa dipulihkan. Kalau bukan kamu yang menghapusnya, hubungi kami.')
            ->headline('Akunmu sudah dihapus')
            ->line('Akun Sakala kamu sudah dihapus dan tidak bisa dipulihkan. Kamu tidak perlu melakukan apa-apa lagi.')
            ->line('Proyekmu sudah dihentikan, dan datanya dihapus permanen setelah masa simpan yang tertulis di Pemberitahuan Privasi.')
            ->line('Izin GitHub App Sakala di akun GitHub-mu tidak ikut dicabut. Kalau dulu kamu memasangnya, hapus dari pengaturan GitHub-mu.')
            ->line("Kalau bukan kamu yang menghapus akun ini, segera kirim email ke {$contact}.")
            ->reason('Kamu menerima email ini karena alamat ini adalah email akun Sakala yang baru saja dihapus.');
    }
}
