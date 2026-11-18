<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Enums\EdgeHealthReason;
use App\Models\User;
use App\Notifications\Account\AccountDeletedNotification;
use App\Notifications\Auth\ConfirmEmailChangeNotification;
use App\Notifications\Auth\EmailChangeNoticeNotification;
use App\Notifications\Auth\ExistingAccountRegistrationNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Notifications\Edge\EdgeRecoveredNotification;
use App\Notifications\Edge\EdgeUnhealthyNotification;
use App\Notifications\Project\ProjectSuspendedNotification;
use App\Notifications\Workspace\WorkspaceInvitationNotification;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use LogicException;

/**
 * Every email the API sends, built with made-up data, for `php artisan
 * mail:preview` and the mail tests. Nothing here sends anything. Adding a
 * notification with a mail channel means adding it here; MailCatalogTest fails
 * otherwise.
 */
final class MailPreviewCatalog
{
    /** @return array<string, array{trigger: string, notification: Notification, notifiable: object}> */
    public function previews(): array
    {
        $user = (new User)->forceFill([
            'id' => 42,
            'name' => 'Rani Lestari',
            'email' => 'rani@example.test',
        ]);
        $address = (new AnonymousNotifiable)->route('mail', 'rani@example.test');
        $newAddress = (new AnonymousNotifiable)->route('mail', 'rani.baru@example.test');
        $expiry = now()->addMinutes((int) config('auth.verification.expire', 60));
        $edgeUnhealthySince = CarbonImmutable::parse('2026-11-18 07:05:00', 'UTC');

        return [
            'verify-email' => [
                'trigger' => 'Daftar dengan email, atau minta kirim ulang verifikasi',
                'notification' => new VerifyEmailNotification,
                'notifiable' => $user,
            ],
            'reset-password' => [
                'trigger' => 'Lupa kata sandi',
                'notification' => new ResetPasswordNotification('contoh-token-reset'),
                'notifiable' => $user,
            ],
            'existing-account' => [
                'trigger' => 'Daftar dengan email yang sudah punya akun',
                'notification' => new ExistingAccountRegistrationNotification,
                'notifiable' => $user,
            ],
            'email-change-confirm' => [
                'trigger' => 'Ganti email: dikirim ke alamat baru',
                'notification' => new ConfirmEmailChangeNotification(
                    'Rani Lestari',
                    URL::temporarySignedRoute('email-change.confirm', $expiry, ['user' => 42, 'hash' => sha1('rani.baru@example.test')]),
                ),
                'notifiable' => $newAddress,
            ],
            'email-change-requested' => [
                'trigger' => 'Ganti email: dikirim ke alamat lama saat diminta',
                'notification' => new EmailChangeNoticeNotification('Rani Lestari', 'rani.baru@example.test', false),
                'notifiable' => $address,
            ],
            'email-change-applied' => [
                'trigger' => 'Ganti email: dikirim ke alamat lama setelah dikonfirmasi',
                'notification' => new EmailChangeNoticeNotification('Rani Lestari', 'rani.baru@example.test', true),
                'notifiable' => $address,
            ],
            'workspace-invitation' => [
                'trigger' => 'Owner workspace tim mengundang alamat email',
                'notification' => new WorkspaceInvitationNotification(
                    invitationId: '019a0000-0000-7000-8000-000000000001',
                    token: 'contoh-token-undangan',
                    workspaceName: 'Tim Facility',
                    inviterName: 'Budi Santoso',
                    role: 'member',
                    ttlHours: (int) config('sakala.workspaces.invitation_ttl_hours', 168),
                    expiresAt: now()->addHours((int) config('sakala.workspaces.invitation_ttl_hours', 168)),
                ),
                'notifiable' => $address,
            ],
            'project-suspended' => [
                'trigger' => 'Admin menangguhkan proyek',
                'notification' => new ProjectSuspendedNotification(
                    '019a0000-0000-7000-8000-000000000002',
                    'toko-saya',
                    'Halaman login yang meniru situs bank.',
                ),
                'notifiable' => $user,
            ],
            'edge-unhealthy' => [
                'trigger' => 'edge:check-health melihat Sakala Edge menjadi tidak sehat',
                'notification' => new EdgeUnhealthyNotification(
                    edgeName: 'edge-jkt-1',
                    unhealthySince: $edgeUnhealthySince,
                    reasons: [EdgeHealthReason::Stale, EdgeHealthReason::Status, EdgeHealthReason::RevisionBehind],
                    lastSeenAt: $edgeUnhealthySince->subMinutes(5),
                    status: 'below_expected_minimum',
                    appliedRevision: '5f2c0e9d4b6a7c1e3f8a2d9b0c4e6f1a7b3d5c9e2f0a8b6d4c1e7f3a9b5d2c0e',
                    currentRevision: '9a1b3c5d7e9f0a2b4c6d8e0f1a3b5c7d9e1f3a5b7c9d0e2f4a6b8c0d2e4f6a8b',
                ),
                'notifiable' => $user,
            ],
            'edge-recovered' => [
                'trigger' => 'edge:check-health melihat Sakala Edge sehat kembali',
                'notification' => new EdgeRecoveredNotification('edge-jkt-1', $edgeUnhealthySince, $edgeUnhealthySince->addMinutes(65)),
                'notifiable' => $user,
            ],
            'account-deleted' => [
                'trigger' => 'Pengguna menghapus akunnya',
                'notification' => new AccountDeletedNotification,
                'notifiable' => $address,
            ],
        ];
    }

    public function mail(Notification $notification, object $notifiable): MailMessage
    {
        if (! method_exists($notification, 'toMail')) {
            throw new LogicException($notification::class.' has no mail version.');
        }

        $mail = $notification->toMail($notifiable);

        if (! $mail instanceof MailMessage) {
            throw new LogicException($notification::class.' does not build a MailMessage.');
        }

        return $mail;
    }

    /** @return array{subject: string, html: string, text: string} */
    public function render(MailMessage $mail): array
    {
        return [
            'subject' => (string) $mail->subject,
            'html' => (string) $mail->render(),
            'text' => (string) app(Markdown::class)->renderText($mail->markdown, $mail->data()),
        ];
    }
}
