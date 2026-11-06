<?php

declare(strict_types=1);

namespace App\Data\Auth;

final readonly class PasswordResetData
{
    public function __construct(
        public string $email,
        public string $token,
        public string $password,
    ) {}
}
