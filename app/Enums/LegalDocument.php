<?php

declare(strict_types=1);

namespace App\Enums;

enum LegalDocument: string
{
    case Terms = 'terms';
    case AcceptableUse = 'acceptable_use';
    case PrivacyNotice = 'privacy_notice';

    public function currentVersion(): string
    {
        return (string) config("sakala.legal.versions.{$this->value}");
    }

    /** The privacy notice is acknowledged: processing does not rest on consent to it. */
    public function action(): LegalAcceptanceAction
    {
        return $this === self::PrivacyNotice
            ? LegalAcceptanceAction::Acknowledged
            : LegalAcceptanceAction::Accepted;
    }
}
