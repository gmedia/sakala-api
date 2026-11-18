<?php

declare(strict_types=1);

namespace App\Data\Runtime;

/** A region a project may run in (GetAvailableRegionsAction). */
final readonly class RegionData
{
    public function __construct(
        /** The value of a project's `region` or a profile's `default_region`, for example `jkt`. */
        public string $slug,
    ) {}
}
