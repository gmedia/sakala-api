<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\AuthOptionsData;
use App\Enums\LegalDocument;

final class GetAuthOptionsAction
{
    /**
     * Reads config only, so the signed-out login page can call it on every
     * visit without touching the database.
     */
    public function handle(): AuthOptionsData
    {
        return new AuthOptionsData(
            emailDeliveryEnabled: (bool) config('sakala.email_delivery_enabled'),
            githubEnabled: $this->isConfigured('services.github_app'),
            googleEnabled: $this->isConfigured('services.google'),
            termsVersion: LegalDocument::Terms->currentVersion(),
            acceptableUseVersion: LegalDocument::AcceptableUse->currentVersion(),
            privacyNoticeVersion: LegalDocument::PrivacyNotice->currentVersion(),
        );
    }

    private function isConfigured(string $service): bool
    {
        return filled(config("{$service}.client_id"))
            && filled(config("{$service}.client_secret"))
            && filled(config("{$service}.redirect"));
    }
}
