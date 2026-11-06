<?php

declare(strict_types=1);

namespace App\Notifications\Project;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The email copy of the suspension notice; the in-app one goes through UserNotifier. */
final class ProjectSuspendedNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        public readonly string $projectName,
        public readonly string $reason,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $contact = (string) config('sakala.contact.abuse_email');

        return (new MailMessage)
            ->subject("Proyek {$this->projectName} ditangguhkan")
            ->line("Proyek {$this->projectName} ditangguhkan oleh admin Sakala. Trafiknya diputus dan container-nya dihentikan, tetapi datanya tidak dihapus.")
            ->line("Alasannya: {$this->reason}")
            ->line("Kalau menurutmu keputusan ini keliru, kirim email ke {$contact} dari email akunmu, lalu sebutkan nama proyeknya.");
    }
}
