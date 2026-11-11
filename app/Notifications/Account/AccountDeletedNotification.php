<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Goes to a routed address, because the user it is about no longer exists, so
 * the queue holds the address only until this is sent.
 */
final class AccountDeletedNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $contact = (string) config('sakala.contact.privacy_email');

        return (new MailMessage)
            ->subject('Akun Sakala kamu sudah dihapus')
            ->line('Akun Sakala kamu sudah dihapus dan tidak bisa dipulihkan.')
            ->line('Proyekmu dihentikan, dan datanya dihapus permanen setelah masa simpan yang tertulis di Pemberitahuan Privasi. Akses GitHub dicabut dari pengaturan GitHub-mu sendiri.')
            ->line("Kalau bukan kamu yang menghapusnya, segera kirim email ke {$contact}.");
    }
}
