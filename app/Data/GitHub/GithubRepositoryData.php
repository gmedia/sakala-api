<?php

declare(strict_types=1);

namespace App\Data\GitHub;

final readonly class GithubRepositoryData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $fullName,
        public string $cloneUrl,
        public string $defaultBranch,
        public string $pushedAt,
        public bool $private,
    ) {}
}
