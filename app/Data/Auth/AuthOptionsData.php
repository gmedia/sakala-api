<?php

declare(strict_types=1);

namespace App\Data\Auth;

final readonly class AuthOptionsData
{
    public function __construct(
        public bool $emailDeliveryEnabled,
        public bool $githubEnabled,
        public bool $googleEnabled,
        public string $termsVersion,
        public string $acceptableUseVersion,
        public string $privacyNoticeVersion,
    ) {}
}
