<?php

declare(strict_types=1);

namespace App\Data\Project;

final readonly class GetProjectCollectionData
{
    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly string $search,
        public readonly string $filter
    ) {}
}
