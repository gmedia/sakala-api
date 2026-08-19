<?php

declare(strict_types=1);

namespace App\Data\Deployment;

final readonly class DeploymentPaginateData
{
    public function __construct(
        public int $page,
        public int $perPage,
    ) {}
}
