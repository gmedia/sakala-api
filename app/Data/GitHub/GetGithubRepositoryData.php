<?php

declare(strict_types=1);

namespace App\Data\GitHub;

final class GetGithubRepositoryData
{
    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly string $search,
    ) {
    }
}
