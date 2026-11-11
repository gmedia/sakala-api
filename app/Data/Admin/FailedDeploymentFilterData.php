<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\DeploymentFailureCategory;

final readonly class FailedDeploymentFilterData
{
    public function __construct(
        public int $page,
        public int $perPage,
        public TimeRangeData $range,
        public ?string $search = null,
        public ?DeploymentFailureCategory $category = null,
        public ?string $code = null,
    ) {}
}
