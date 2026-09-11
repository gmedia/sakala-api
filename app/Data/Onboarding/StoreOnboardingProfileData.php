<?php

declare(strict_types=1);

namespace App\Data\Onboarding;

use App\Enums\OnboardingProfile;

final readonly class StoreOnboardingProfileData
{
    public function __construct(
        public ?string $name = null,
        public ?OnboardingProfile $role = null,
        public bool $skip = false,
    ) {}
}
