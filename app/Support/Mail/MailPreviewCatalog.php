<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Data\Notification\ProjectAlertMailData;
use App\Enums\DeploymentStatus;
use App\Enums\EdgeHealthReason;
use App\Enums\ProjectAlert;
use App\Models\User;
use App\Notifications\Account\AccountDeletedNotification;
use App\Notifications\Auth\ConfirmEmailChangeNotification;
use App\Notifications\Auth\EmailChangeNoticeNotification;
use App\Notifications\Auth\ExistingAccountRegistrationNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Notifications\Edge\EdgeRecoveredNotification;
use App\Notifications\Edge\EdgeUnhealthyNotification;
use App\Notifications\Legal\LegalDocumentChangedNotification;
use App\Notifications\Messages\SakalaMailMessage;
use App\Notifications\Project\ProjectAlertMailNotification;
use App\Notifications\Project\ProjectSuspendedNotification;
use App\Notifications\Workspace\WorkspaceInvitationNotification;
use App\Support\Legal\LegalChangeCatalog;
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
        $projectId = '019a0000-0000-7000-8000-000000000003';
        $deploymentId = '019a0000-0000-7000-8000-000000000004';
        $failedDeployment = new ProjectAlertMailData(
            alert: ProjectAlert::DeploymentFailed,
            projectId: $projectId,
            projectName: 'toko-kopi-web',
            deploymentId: $deploymentId,
            deploymentSequence: 5,
            failureStage: DeploymentStatus::Building,
            summary: 'Deployment gagal saat proses build aplikasi.',
            recoveryHint: 'Buka log deployment untuk melihat error-nya, perbaiki di repository (dependency, script build, atau Dockerfile), lalu commit dan deploy ulang.',
            previousVersionServing: true,
        );

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
                    email: 'rani@example.test',
                ),
                'notifiable' => $address,
            ],
            'workspace-invitation-owner' => [
                'trigger' => 'Owner workspace tim mengundang alamat email sebagai pemilik',
                'notification' => new WorkspaceInvitationNotification(
                    invitationId: '019a0000-0000-7000-8000-000000000001',
                    token: 'contoh-token-undangan',
                    workspaceName: 'Tim Facility',
                    inviterName: 'Budi Santoso',
                    role: 'owner',
                    ttlHours: (int) config('sakala.workspaces.invitation_ttl_hours', 168),
                    expiresAt: now()->addHours((int) config('sakala.workspaces.invitation_ttl_hours', 168)),
                    email: 'rani@example.test',
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
            'project-deployment-failed' => [
                'trigger' => 'Deployment gagal, untuk yang memintanya dan owner workspace dengan email deployment gagal aktif',
                'notification' => new ProjectAlertMailNotification($failedDeployment),
                'notifiable' => $user,
            ],
            'project-deployment-failed-repeated' => [
                'trigger' => 'Deployment gagal lagi setelah jendela penggabungan, dengan kegagalan yang tidak dikirim di antaranya',
                'notification' => new ProjectAlertMailNotification($failedDeployment, heldBack: 3),
                'notifiable' => $user,
            ],
            'project-deployment-succeeded' => [
                'trigger' => 'Deployment berhasil, hanya kalau email deployment berhasil dinyalakan',
                'notification' => new ProjectAlertMailNotification(new ProjectAlertMailData(
                    alert: ProjectAlert::DeploymentSucceeded,
                    projectId: $projectId,
                    projectName: 'toko-kopi-web',
                    deploymentId: $deploymentId,
                    deploymentSequence: 6,
                )),
                'notifiable' => $user,
            ],
            'project-app-crashed' => [
                'trigger' => 'Heartbeat melaporkan aplikasi yang sedang berjalan berhenti sendiri',
                'notification' => new ProjectAlertMailNotification(new ProjectAlertMailData(
                    alert: ProjectAlert::AppCrashed,
                    projectId: $projectId,
                    projectName: 'toko-kopi-web',
                    deploymentId: $deploymentId,
                    deploymentSequence: 6,
                )),
                'notifiable' => $user,
            ],
            'project-auto-deploy-skipped' => [
                'trigger' => 'Push ke GitHub tidak di-deploy karena batas workspace atau sebab lain',
                'notification' => new ProjectAlertMailNotification(new ProjectAlertMailData(
                    alert: ProjectAlert::AutoDeploySkipped,
                    projectId: $projectId,
                    projectName: 'toko-kopi-web',
                    summary: 'Workspace ini sudah mencapai batas 20 build per jam, jadi push terbaru tidak di-deploy. Deploy manual atau push ulang sekitar 25 menit lagi.',
                    previousVersionServing: true,
                )),
                'notifiable' => $user,
            ],
            'account-deleted' => [
                'trigger' => 'Pengguna menghapus akunnya',
                'notification' => new AccountDeletedNotification,
                'notifiable' => $address,
            ],
            'legal-document-changed' => [
                'trigger' => 'Maintainer menjalankan legal:announce-change untuk versi dokumen hukum yang baru',
                'notification' => new LegalDocumentChangedNotification(
                    change: app(LegalChangeCatalog::class)->find('privasi-v3') ?? throw new LogicException('privasi-v3 has no summary.'),
                    effectiveAt: CarbonImmutable::parse('2026-12-07 00:00:00', SakalaMailMessage::TIMEZONE),
                ),
                'notifiable' => $user,
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
