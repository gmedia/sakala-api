<?php

declare(strict_types=1);

namespace App\Data\Auth;

final readonly class LoginData
{
    public function __construct(
        public string $email,
        public string $password,
    ) {}
}
