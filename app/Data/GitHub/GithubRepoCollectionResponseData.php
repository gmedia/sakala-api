<?php

namespace App\Data\GitHub;

final readonly class GithubRepoCollectionResponseData
{
    public function __construct(
        public array $repositories,
        public int $page,
        public int $perPage,
        public int $lastPage,
        public bool $hasNextPage,
        public bool $hasPreviousPage,
    ) {}
}