<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

enum LegalDocument: string
{
    case Terms = 'terms';
    case AcceptableUse = 'acceptable_use';
    case PrivacyNotice = 'privacy_notice';

    /**
     * The version in force now. A newer version published ahead of its
     * effective date leaves the previous one current until that moment.
     */
    public function currentVersion(): string
    {
        $effectiveAt = $this->effectiveAt();
        $previous = config("sakala.legal.previous_versions.{$this->value}");

        if ($effectiveAt !== null && now()->lt($effectiveAt) && is_string($previous) && $previous !== '') {
            return $previous;
        }

        return $this->latestVersion();
    }

    /** The newest published version, which may not be in force yet. */
    public function latestVersion(): string
    {
        return (string) config("sakala.legal.versions.{$this->value}");
    }

    /** When latestVersion() takes effect; null when no date is set, so it is in force already. */
    public function effectiveAt(): ?CarbonImmutable
    {
        $value = config("sakala.legal.effective_at.{$this->value}");

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    /** The privacy notice is acknowledged: processing does not rest on consent to it. */
    public function action(): LegalAcceptanceAction
    {
        return $this === self::PrivacyNotice
            ? LegalAcceptanceAction::Acknowledged
            : LegalAcceptanceAction::Accepted;
    }

    /** The Indonesian title, as on the site. */
    public function title(): string
    {
        return match ($this) {
            self::Terms => 'Syarat Layanan',
            self::AcceptableUse => 'Kebijakan Penggunaan Wajar',
            self::PrivacyNotice => 'Pemberitahuan Privasi',
        };
    }

    /** The document's page on the site, relative to `sakala.landing_url`. */
    public function path(): string
    {
        return match ($this) {
            self::Terms => '/syarat',
            self::AcceptableUse => '/penggunaan-wajar',
            self::PrivacyNotice => '/privasi',
        };
    }
}
