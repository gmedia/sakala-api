<?php

declare(strict_types=1);

namespace App\Data\Auth;

final readonly class VerificationNotificationData
{
    public function __construct(
        public bool $requestAccepted = true,
    ) {}
}
