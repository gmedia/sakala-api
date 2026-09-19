<?php

declare(strict_types=1);

namespace App\Data\Agent;

use Carbon\CarbonImmutable;

final readonly class RepositoryCredentialData
{
    public function __construct(
        public string $username,
        public string $token,
        public CarbonImmutable $expiresAt,
    ) {}
}
