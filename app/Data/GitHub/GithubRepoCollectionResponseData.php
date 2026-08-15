<?php

declare(strict_types=1);

namespace App\Data\GitHub;

final readonly class GithubRepoCollectionResponseData
{
    /**
     * @param  array<int, GithubRepositoryData>  $repositories
     */
    public function __construct(
        public array $repositories,
        public int $page,
        public int $perPage,
        public int $lastPage,
        public bool $hasNextPage,
        public bool $hasPreviousPage,
    ) {}
}
