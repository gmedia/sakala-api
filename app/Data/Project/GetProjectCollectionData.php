<?php

declare(strict_types=1);

namespace App\Data\Project;

final readonly class GetProjectCollectionData
{
    public function __construct(
        public int $page,
        public int $perPage,
        public string $search,
        public string $filter,
        /** Null lists every workspace the user belongs to. */
        public ?string $workspaceId = null,
    ) {}
}
