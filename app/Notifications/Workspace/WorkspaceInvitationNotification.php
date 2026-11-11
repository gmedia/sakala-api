<?php

declare(strict_types=1);

namespace App\Notifications\Workspace;

use App\Enums\WorkspaceRole;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
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
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('sakala.console_url'), '/')
            .'/workspace-invitations/'.rawurlencode($this->invitationId)
            .'?token='.rawurlencode($this->token);
        $days = max(1, intdiv($this->ttlHours, 24));

        return (new MailMessage)
            ->subject("Undangan bergabung ke {$this->workspaceName} di Sakala")
            ->line("{$this->inviterName} mengundangmu bergabung ke workspace tim {$this->workspaceName} di Sakala sebagai {$this->roleLabel()}.")
            ->action('Lihat undangan', $url)
            ->line("Undangan ini berlaku {$days} hari dan hanya bisa dipakai sekali. Kalau belum punya akun Sakala, daftar dulu, lalu buka tautan ini lagi.")
            ->line('Kalau kamu tidak mengenal pengirimnya, abaikan email ini.');
    }

    private function roleLabel(): string
    {
        return (WorkspaceRole::tryFrom($this->role) ?? WorkspaceRole::Member)->label();
    }
}
