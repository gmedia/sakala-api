<?php

declare(strict_types=1);

namespace App\Data\Deployment;

use App\Data\Runtime\RuntimeResourceLimitsData;
use App\Enums\DeploymentTrigger;

final readonly class CreateDeploymentData
{
    public function __construct(
        public string $branch,
        public ?string $idempotencyKey = null,
        public ?RuntimeResourceLimitsData $requested_resources = null,
        public DeploymentTrigger $trigger = DeploymentTrigger::Manual,
        public ?string $commit_sha = null,
        public ?string $commit_message = null,
    ) {}
}
