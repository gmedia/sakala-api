<?php

declare(strict_types=1);

namespace App\Data\GitHub;

final readonly class GetGithubRepositoryData
{
    public function __construct(
        public int $page,
        public int $perPage,
        public string $search,
    ) {
    }
}
