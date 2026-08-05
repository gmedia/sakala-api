<?php

declare(strict_types=1);

namespace App\Data\Onboarding;

use App\Enums\OnboardingSource;

final readonly class StoreOnboardingSourceData
{
    public function __construct(
        public ?OnboardingSource $source = null,
        public bool $skip = false,
    ) {}
}
