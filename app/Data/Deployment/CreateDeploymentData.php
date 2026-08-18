<?php

declare(strict_types=1);

namespace App\Data\Deployment;

final readonly class CreateDeploymentData
{
    public function __construct(
        public string $branch,
    ) {}
}
