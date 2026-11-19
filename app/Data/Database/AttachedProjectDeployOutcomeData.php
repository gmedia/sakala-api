<?php

declare(strict_types=1);

namespace App\Data\Database;

use App\Enums\DatabaseRedeploySkipReason;

/**
 * What deploying an attached project on someone's behalf did: a deployment,
 * or the reason there is none.
 */
final readonly class AttachedProjectDeployOutcomeData
{
    private function __construct(
        public ?string $deploymentId,
        public ?DatabaseRedeploySkipReason $skippedReason,
    ) {}

    public static function started(string $deploymentId): self
    {
        return new self($deploymentId, null);
    }

    public static function skipped(DatabaseRedeploySkipReason $reason): self
    {
        return new self(null, $reason);
    }

    /** @return array{deployment_id: string|null, skipped_reason: string|null} */
    public function toArray(): array
    {
        return [
            'deployment_id' => $this->deploymentId,
            'skipped_reason' => $this->skippedReason?->value,
        ];
    }
}
