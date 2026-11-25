<?php

declare(strict_types=1);

namespace App\Services\CustomDomain;

use App\Data\Notification\ProjectAlertMailData;
use App\Enums\NotificationCategory;
use App\Enums\ProjectAlert;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\User;
use App\Services\Notification\UserNotifier;
use App\Support\CustomDomain\CustomDomainReasonMessage;

/**
 * Tells the people who manage a project what happened to one of its custom
 * domains (ADR-024): in the Console's notification list, and by email to
 * the workspace owners and the person who added the domain for anything
 * they must act on. Everything is sent after the surrounding transaction
 * commits (UserNotifier).
 */
final class CustomDomainNotifier
{
    public function __construct(
        private readonly UserNotifier $notifier,
        private readonly CustomDomainPolicy $policy,
    ) {}

    /** Became active: good news for the Console only. */
    public function verified(ProjectDomain $domain): void
    {
        $this->inApp($domain, 'Domain aktif', "{$domain->hostname} sudah mengarah ke proyek ini. Sertifikat HTTPS-nya terbit dalam beberapa menit.");
    }

    /** Failing for the warning period: one email per failing run. */
    public function failing(ProjectDomain $domain): void
    {
        $disableAt = $domain->failing_since === null ? null : $this->policy->disableAt($domain->failing_since);
        $hours = $disableAt === null ? null : max(1, (int) ceil(now()->diffInMinutes($disableAt, absolute: true) / 60));
        $when = $hours === null ? 'segera' : "dalam sekitar {$hours} jam";

        $this->inApp($domain, 'Domain akan dinonaktifkan', "Pemeriksaan DNS {$domain->hostname} gagal terus. Domain ini dinonaktifkan {$when} kalau record-nya belum diperbaiki.");
        $this->email($domain, ProjectAlert::CustomDomainFailing, detail: $when);
    }

    /** Disabled after failing too long, or by an admin with `$adminReason`. */
    public function disabled(ProjectDomain $domain, ?string $adminReason = null): void
    {
        $message = $adminReason === null
            ? "{$domain->hostname} dinonaktifkan karena pemeriksaan DNS-nya gagal terus. Alamat proyek di Sakala tetap jalan. Domain ini aktif lagi sendiri setelah record-nya diperbaiki."
            : "Admin Sakala menonaktifkan {$domain->hostname}. Alasan: {$adminReason}";

        $this->inApp($domain, 'Domain dinonaktifkan', $message);
        $this->email($domain, ProjectAlert::CustomDomainDisabled, $adminReason === null ? null : "Admin Sakala menonaktifkan domain ini. Alasan: {$adminReason}");
    }

    /** A disabled domain passed again. */
    public function reactivated(ProjectDomain $domain): void
    {
        $this->inApp($domain, 'Domain aktif lagi', "{$domain->hostname} lolos pemeriksaan DNS lagi dan kembali mengarah ke proyek ini.");
        $this->email($domain, ProjectAlert::CustomDomainReactivated, advice: false);
    }

    /** Another project verified the name of this disabled claim. */
    public function released(ProjectDomain $domain): void
    {
        $this->inApp($domain, 'Domain dipakai proyek lain', "{$domain->hostname} sekarang aktif di proyek lain yang membuktikan kepemilikannya. Domain ini tidak bisa aktif lagi di proyek ini; hapus dari daftar domain.");
        $this->email($domain, ProjectAlert::CustomDomainReleased, advice: false);
    }

    private function inApp(ProjectDomain $domain, string $title, string $message): void
    {
        $project = $this->project($domain);

        if ($project !== null) {
            $this->notifier->sendToProject($project, NotificationCategory::ProjectIssue, $title, $message);
        }
    }

    /**
     * @param  string|null  $hint  What to do; the first check reason's advice when null.
     * @param  string|null  $detail  For the failing warning: when the domain is disabled.
     * @param  bool  $advice  False for good news, which needs no advice.
     */
    private function email(ProjectDomain $domain, ProjectAlert $alert, ?string $hint = null, ?string $detail = null, bool $advice = true): void
    {
        $project = $this->project($domain);

        if ($project === null) {
            return;
        }

        $reason = $advice ? ($domain->checkReasons()[0] ?? null) : null;

        $this->notifier->emailProject($project, new ProjectAlertMailData(
            alert: $alert,
            projectId: $project->id,
            projectName: $project->name,
            summary: $domain->hostname,
            recoveryHint: $hint ?? ($reason === null ? null : CustomDomainReasonMessage::for($reason, $domain)),
            detail: $detail,
        ), $domain->created_by === null ? null : User::query()->find($domain->created_by));
    }

    private function project(ProjectDomain $domain): ?Project
    {
        return Project::query()->find($domain->project_id);
    }
}
