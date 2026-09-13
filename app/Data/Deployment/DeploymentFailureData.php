<?php

declare(strict_types=1);

namespace App\Data\Deployment;

use App\Enums\DeploymentFailureCategory;

final readonly class DeploymentFailureData
{
    public function __construct(
        public string $code,
        public DeploymentFailureCategory $category,
        public string $summary,
        public string $recoveryHint,
    ) {}
}
