<?php

declare(strict_types=1);

namespace App\Notifications\Project;

use App\Notifications\Messages\SakalaMailMessage;
use App\Services\Auth\ConsoleAuthenticationRedirect;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

/** The email copy of the suspension notice; the in-app one goes through UserNotifier. */
final class ProjectSuspendedNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        public readonly string $projectId,
        public readonly string $projectName,
        public readonly string $reason,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): SakalaMailMessage
    {
        $contact = (string) config('sakala.contact.abuse_email');
        $message = new SakalaMailMessage;

        if (is_string($name = data_get($notifiable, 'name')) && $name !== '') {
            $message->greeting("Halo {$name},");
        }

        return $message
            ->subject("Proyek {$this->projectName} di Sakala ditangguhkan")
            ->preheader('Trafiknya diputus dan container-nya dihentikan, tetapi datanya tidak dihapus.')
            ->headline("Proyek {$this->projectName} ditangguhkan")
            ->line("Admin Sakala menangguhkan proyek {$this->projectName}. Trafiknya diputus, container-nya dihentikan, dan proyek ini belum bisa di-deploy. Datanya tidak dihapus.")
            ->line("Alasan dari admin: {$this->reason}")
            ->line("Kalau menurutmu keputusan ini keliru, kirim email ke {$contact} dari email akunmu, lalu sebutkan nama proyeknya.")
            ->action('Buka proyek', app(ConsoleAuthenticationRedirect::class)->project($this->projectId))
            ->reason("Kamu menerima email ini karena kamu ikut mengelola proyek {$this->projectName} di Sakala. Pemberitahuan penangguhan selalu dikirim, apa pun pengaturan notifikasimu.");
    }
}
