<?php

declare(strict_types=1);

namespace App\Notifications\Workspace;

use App\Enums\WorkspaceRole;
use App\Notifications\Messages\SakalaMailMessage;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

/**
 * The email invitation. The link carries the only copy of the token; the row
 * keeps its hash. The in-app notice for an existing account goes through
 * UserNotifier and never contains the token.
 */
final class WorkspaceInvitationNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        public readonly string $invitationId,
        public readonly string $token,
        public readonly string $workspaceName,
        public readonly string $inviterName,
        public readonly string $role,
        public readonly int $ttlHours,
        public readonly DateTimeInterface $expiresAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): SakalaMailMessage
    {
        $url = rtrim((string) config('sakala.console_url'), '/')
            .'/workspace-invitations/'.rawurlencode($this->invitationId)
            .'?token='.rawurlencode($this->token);
        $role = WorkspaceRole::tryFrom($this->role) ?? WorkspaceRole::Member;
        $until = SakalaMailMessage::wib($this->expiresAt);

        return (new SakalaMailMessage)
            ->subject("{$this->inviterName} mengundangmu ke {$this->workspaceName} di Sakala")
            ->preheader("Terima atau tolak undangan ini sebelum {$until}.")
            ->headline("Undangan ke workspace {$this->workspaceName}")
            ->line('Buka undangan lewat tombol di bawah untuk menerima atau menolaknya.')
            ->line("{$this->inviterName} mengundangmu bergabung ke workspace {$this->workspaceName} di Sakala sebagai {$role->label()}, yang bisa {$this->abilities($role)}.")
            ->action('Lihat undangan', $url)
            ->line("Undangan ini berlaku {$this->validity()}, sampai {$until}, dan hanya bisa dipakai sekali. Kalau belum punya akun Sakala, daftar dulu, lalu buka tautan ini lagi.")
            ->line('Tautan ini khusus untukmu. Jangan teruskan ke orang lain, karena siapa pun yang membukanya dengan akun Sakala bisa menerima undangan ini.')
            ->line("Kalau kamu tidak mengenal {$this->inviterName}, abaikan email ini.")
            ->reason("Kamu menerima email ini karena {$this->inviterName} memasukkan alamat ini sebagai undangan workspace di Sakala.");
    }

    private function validity(): string
    {
        return $this->ttlHours % 24 === 0
            ? intdiv($this->ttlHours, 24).' hari'
            : $this->ttlHours.' jam';
    }

    private function abilities(WorkspaceRole $role): string
    {
        return match ($role) {
            WorkspaceRole::Owner => 'mengelola anggota, undangan, dan workspace ini, selain semua yang bisa dilakukan anggota',
            WorkspaceRole::Member => 'membuat, men-deploy, dan mengelola proyek di workspace ini, termasuk environment variable-nya',
            WorkspaceRole::Viewer => 'melihat proyek, deployment, dan log di workspace ini, tanpa bisa mengubahnya atau membaca nilai rahasia',
        };
    }
}
