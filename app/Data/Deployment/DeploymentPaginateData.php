<?php

declare(strict_types=1);

namespace App\Data\Deployment;

final readonly class DeploymentPaginateData
{
    public function __construct(
        public int $perPage,
        public ?string $cursor = null,
        public bool $newestFirst = false,
        /** Only log lines of this process: a worker name, `job`, or `web` for the deployment's own lines (ADR-022). */
        public ?string $process = null,
    ) {}
}
