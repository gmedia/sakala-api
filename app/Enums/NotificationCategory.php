<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationCategory: string
{
    case DeploymentSuccess = 'deployment_success';
    case DeploymentFailure = 'deployment_failure';
    case ProjectIssue = 'project_issue';
    case AccountSecurity = 'account_security';
    case Workspace = 'workspace';

    /**
     * Whether email is on for someone who never chose, or null when the category
     * has no email channel to choose. Failures and problems are what people need
     * to hear about while away; a success is only worth a glance at the bell.
     * Account security and workspace events that matter already send their own
     * email (email change, invitation), whatever the preference.
     */
    public function emailDefault(): ?bool
    {
        return match ($this) {
            self::DeploymentFailure, self::ProjectIssue => true,
            self::DeploymentSuccess => false,
            self::AccountSecurity, self::Workspace => null,
        };
    }

    public function supportsEmail(): bool
    {
        return $this->emailDefault() !== null;
    }

    /** @return list<self> */
    public static function emailable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $category): bool => $category->supportsEmail()));
    }

    /** @return list<string> */
    public static function emailableValues(): array
    {
        return array_map(static fn (self $category): string => $category->value, self::emailable());
    }

    /** The name the console settings page shows, used in emails about the category. */
    public function label(): string
    {
        return match ($this) {
            self::DeploymentSuccess => 'Deployment berhasil',
            self::DeploymentFailure => 'Deployment gagal',
            self::ProjectIssue => 'Masalah proyek',
            self::AccountSecurity => 'Perubahan kata sandi dan email',
            self::Workspace => 'Undangan dan keanggotaan tim',
        };
    }
}
