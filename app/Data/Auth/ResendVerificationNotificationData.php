<?php

declare(strict_types=1);

namespace App\Data\Auth;

final readonly class ResendVerificationNotificationData
{
    public function __construct(
        public string $email,
    ) {}
}
