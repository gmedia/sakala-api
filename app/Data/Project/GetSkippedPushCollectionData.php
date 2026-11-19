<?php

declare(strict_types=1);

namespace App\Data\Project;

use Carbon\CarbonImmutable;

final readonly class GetSkippedPushCollectionData
{
    public function __construct(
        public int $page,
        public int $perPage,
        public ?CarbonImmutable $since,
    ) {}
}
