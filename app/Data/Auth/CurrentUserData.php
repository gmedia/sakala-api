<?php

declare(strict_types=1);

namespace App\Data\Auth;

use App\Enums\OnboardingSource;
use App\Enums\UserRole;
use Carbon\CarbonImmutable;

final readonly class CurrentUserData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public ?string $avatarUrl,
        public UserRole $role,
        public ?OnboardingSource $onboardingSource,
        public ?CarbonImmutable $onboardingCompletedAt,
        public ?CarbonImmutable $lastLoginAt,
    ) {}
}
