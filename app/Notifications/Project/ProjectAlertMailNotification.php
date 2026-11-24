<?php

declare(strict_types=1);

namespace App\Notifications\Project;

use App\Data\Notification\ProjectAlertMailData;
use App\Enums\ProjectAlert;
use App\Notifications\Messages\SakalaMailMessage;
use App\Services\Auth\ConsoleAuthenticationRedirect;
use App\Services\Notification\NotificationUnsubscribeLinks;
use App\Services\Schedule\SchedulePolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;
use LogicException;

/**
 * The email copy of a project alert, for people who left email on for its
 * category. UserNotifier decides who gets it and how often; the in-app
 * notification is sent separately and does not depend on this one.
 */
final class ProjectAlertMailNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /** @param  int  $heldBack  Alerts of the same kind on the project that were not emailed since the last email. */
    public function __construct(
        public readonly ProjectAlertMailData $data,
        public readonly int $heldBack = 0,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): SakalaMailMessage
    {
        $userId = data_get($notifiable, 'id');

        if (! is_int($userId)) {
            throw new LogicException('An alert email goes to a user account.');
        }

        $category = $this->data->alert->category();
        $links = app(NotificationUnsubscribeLinks::class);
        $oneClick = $links->oneClick($userId, $category);

        $message = match ($this->data->alert) {
            ProjectAlert::DeploymentFailed => $this->deploymentFailed(),
            ProjectAlert::DeploymentSucceeded => $this->deploymentSucceeded(),
            ProjectAlert::AppCrashed => $this->appCrashed(),
            ProjectAlert::AutoDeploySkipped => $this->autoDeploySkipped(),
            ProjectAlert::WorkerCrashed => $this->workerCrashed(),
            ProjectAlert::ScheduleFailing => $this->scheduleFailing(),
            ProjectAlert::SchedulePaused => $this->schedulePaused(),
        };

        return $message
            ->reason("Kamu menerima email ini karena kamu ikut mengelola proyek {$this->data->projectName} di Sakala dan email {$this->categoryName()} sedang aktif di pengaturan notifikasimu.")
            ->preferences(
                category: $this->categoryName(),
                settingsUrl: $this->console()->notificationSettings(),
                unsubscribeUrl: $links->confirmPage($oneClick),
                oneClickUrl: $oneClick,
            );
    }

    private function deploymentFailed(): SakalaMailMessage
    {
        $name = $this->data->projectName;
        $stage = $this->data->failureStage?->label();
        $message = (new SakalaMailMessage)
            ->subject($stage === null ? "Deployment {$name} di Sakala gagal" : "Deployment {$name} di Sakala gagal di tahap {$stage}")
            ->preheader($this->data->summary ?? 'Buka halaman deployment untuk melihat penyebabnya.')
            ->headline("{$this->deploymentName()} proyek {$name} gagal")
            ->line('Buka deployment ini untuk melihat penyebabnya, perbaiki, lalu deploy ulang. '.($this->data->previousVersionServing
                ? 'Versi sebelumnya masih melayani pengunjung.'
                : 'Sampai ada deployment yang berhasil, proyek ini belum bisa dibuka pengunjung.'));

        if ($stage !== null) {
            $message->line("Gagal di tahap: {$stage}.");
        }

        if ($this->data->summary !== null) {
            $message->line("Penyebab: {$this->data->summary}");
        }

        if ($this->data->recoveryHint !== null) {
            $message->line("Saran: {$this->data->recoveryHint}");
        }

        $this->heldBackLine($message, "Ada {$this->heldBack} kegagalan lagi di proyek ini sejak email terakhir.");

        return $message
            ->action('Lihat deployment', $this->deploymentUrl())
            ->line('Di halaman itu, tombol Salin ringkasan error menyalin tahap, penyebab, dan baris terakhir log, supaya mudah ditanyakan ke teman atau dosen. Periksa dulu sebelum membagikannya, karena log bisa memuat nilai rahasia.');
    }

    private function deploymentSucceeded(): SakalaMailMessage
    {
        $name = $this->data->projectName;
        $message = (new SakalaMailMessage)
            ->subject("Deployment {$name} di Sakala berhasil")
            ->preheader('Versi barunya sudah melayani pengunjung.')
            ->headline("{$this->deploymentName()} proyek {$name} berhasil")
            ->line("Versi baru proyek {$name} sudah melayani pengunjung. Tidak ada yang perlu kamu lakukan.");

        $this->heldBackLine($message, "Ada {$this->heldBack} deployment lain di proyek ini yang juga berhasil sejak email terakhir.");

        return $message->action('Lihat deployment', $this->deploymentUrl());
    }

    private function appCrashed(): SakalaMailMessage
    {
        $name = $this->data->projectName;
        $message = (new SakalaMailMessage)
            ->subject("Aplikasi {$name} di Sakala berhenti sendiri")
            ->preheader('Pengunjung bisa mendapat error sampai penyebabnya diperbaiki.')
            ->headline("Aplikasi {$name} berhenti sendiri")
            ->line('Buka log deployment yang sedang berjalan untuk melihat kenapa aplikasinya berhenti, perbaiki, lalu deploy ulang.')
            ->line('Aplikasinya mungkin sedang dimulai ulang, jadi aksesnya bisa terputus-putus. Penyebab yang sering muncul: perintah start yang keliru, port yang tidak cocok, environment variable yang belum diisi, atau memori yang habis.');

        $this->heldBackLine($message, "Aplikasi ini berhenti {$this->heldBack} kali lagi sejak email terakhir.");

        return $message->action('Lihat deployment', $this->deploymentUrl());
    }

    private function autoDeploySkipped(): SakalaMailMessage
    {
        $name = $this->data->projectName;
        $message = (new SakalaMailMessage)
            ->subject("Push terbaru ke {$name} di Sakala tidak di-deploy")
            ->preheader($this->data->summary ?? 'Auto-deploy melewati push terbaru.')
            ->headline("Auto-deploy {$name} dilewati");

        if ($this->data->summary !== null) {
            $message->line($this->data->summary);
        }

        if ($this->data->previousVersionServing) {
            $message->line('Versi yang sedang berjalan tidak berubah dan tetap melayani pengunjung.');
        }

        $this->heldBackLine($message, "Ada {$this->heldBack} push lagi yang juga tidak di-deploy sejak email terakhir.");

        return $message->action('Buka proyek', $this->console()->project($this->data->projectId));
    }

    private function workerCrashed(): SakalaMailMessage
    {
        $name = $this->data->projectName;
        $worker = $this->data->summary ?? 'worker';
        $message = (new SakalaMailMessage)
            ->subject("Worker {$worker} proyek {$name} di Sakala berhenti sendiri")
            ->preheader('Aplikasi web tetap melayani pengunjung, tetapi pekerjaan latar worker ini tertunda.')
            ->headline("Worker {$worker} proyek {$name} berhenti sendiri")
            ->line('Aplikasi web tetap melayani pengunjung. Worker ini dimulai ulang terus oleh Sakala, jadi pekerjaan latarnya, misalnya antrean, tertunda sampai penyebabnya diperbaiki.')
            ->line('Buka log deployment dan pilih proses worker ini untuk melihat pesan terakhir sebelum berhenti. Penyebab yang sering muncul: perintah worker yang keliru, environment variable yang belum diisi, atau memori yang habis.');

        $this->heldBackLine($message, "Worker di proyek ini berhenti {$this->heldBack} kali lagi sejak email terakhir.");

        return $message->action('Buka proyek', $this->console()->project($this->data->projectId));
    }

    private function scheduleFailing(): SakalaMailMessage
    {
        $name = $this->data->projectName;
        $schedule = $this->data->summary ?? 'jadwal';
        $message = (new SakalaMailMessage)
            ->subject("Jadwal {$schedule} proyek {$name} di Sakala gagal")
            ->preheader('Sakala memberi tahu lagi saat jadwal ini berhasil.')
            ->headline("Jadwal {$schedule} proyek {$name} gagal")
            ->line('Run terakhir jadwal ini gagal, melewati batas waktu, atau hilang karena server berhenti. Buka riwayat jadwal untuk melihat kode keluar dan log-nya.')
            ->line('Sakala tidak mengirim email untuk setiap kegagalan berikutnya. Kamu diberi tahu lagi saat jadwal ini berhasil, atau saat jadwal dijeda karena gagal terus.');

        $this->heldBackLine($message, "Ada {$this->heldBack} jadwal lain di proyek ini yang juga mulai gagal sejak email terakhir.");

        return $message->action('Buka proyek', $this->console()->project($this->data->projectId));
    }

    private function schedulePaused(): SakalaMailMessage
    {
        $name = $this->data->projectName;
        $schedule = $this->data->summary ?? 'jadwal';
        $failures = app(SchedulePolicy::class)->pauseAfterFailures();
        $message = (new SakalaMailMessage)
            ->subject("Jadwal {$schedule} proyek {$name} di Sakala dijeda")
            ->preheader("Jadwal ini gagal {$failures} kali berturut-turut.")
            ->headline("Jadwal {$schedule} proyek {$name} dijeda")
            ->line("Jadwal ini gagal {$failures} kali berturut-turut, jadi Sakala menjedanya supaya tidak terus memakai kapasitas. Tidak ada run baru sampai jadwal ini diaktifkan lagi.")
            ->line('Buka riwayat jadwal untuk melihat kode keluar dan log-nya, perbaiki perintahnya atau aplikasinya, lalu aktifkan lagi jadwal ini.');

        $this->heldBackLine($message, "Ada {$this->heldBack} jadwal lain di proyek ini yang juga dijeda sejak email terakhir.");

        return $message->action('Buka proyek', $this->console()->project($this->data->projectId));
    }

    private function heldBackLine(SakalaMailMessage $message, string $sentence): void
    {
        if ($this->heldBack < 1) {
            return;
        }

        $minutes = (int) config('sakala.notifications.email_coalesce_minutes');

        $message->line("{$sentence} Sakala mengirim paling banyak satu email setiap {$minutes} menit untuk kabar yang sama di satu proyek.");
    }

    /** For example "Deployment #5", or "Deployment" when the number is unknown. */
    private function deploymentName(): string
    {
        return $this->data->deploymentSequence === null ? 'Deployment' : "Deployment #{$this->data->deploymentSequence}";
    }

    private function deploymentUrl(): string
    {
        return $this->data->deploymentId === null
            ? $this->console()->project($this->data->projectId)
            : $this->console()->deployment($this->data->projectId, $this->data->deploymentId);
    }

    /** For example "deployment gagal", as the footer says it. */
    private function categoryName(): string
    {
        return mb_strtolower($this->data->alert->category()->label());
    }

    private function console(): ConsoleAuthenticationRedirect
    {
        return app(ConsoleAuthenticationRedirect::class);
    }
}
