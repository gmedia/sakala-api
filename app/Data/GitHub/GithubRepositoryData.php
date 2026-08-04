<?php

declare(strict_types=1);

namespace App\Data\GitHub;

final class GithubRepositoryData
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $fullName,
        public readonly string $cloneUrl,
        public readonly string $defaultBranch,
        public readonly string $pushedAt,
        public readonly bool $private,
    ) {}
}
