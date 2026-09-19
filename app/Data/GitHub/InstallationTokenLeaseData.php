<?php

declare(strict_types=1);

namespace App\Data\GitHub;

use Carbon\CarbonImmutable;

/**
 * A short-lived GitHub App installation token scoped to one repository.
 * Never persisted; the token only lives in the response to the agent.
 */
final readonly class InstallationTokenLeaseData
{
    public function __construct(
        public string $token,
        public CarbonImmutable $expiresAt,
    ) {}
}
