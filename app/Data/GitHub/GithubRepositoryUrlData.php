<?php

declare(strict_types=1);

namespace App\Data\GitHub;

final readonly class GithubRepositoryUrlData
{
    public function __construct(
        public string $repositoryUrl,
    ) {}
}
