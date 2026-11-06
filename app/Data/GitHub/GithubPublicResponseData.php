<?php

declare(strict_types=1);

namespace App\Data\GitHub;

final readonly class GithubPublicResponseData
{
    public function __construct(
        public int $status,
        public mixed $json,
        public bool $hasNextPage = false,
    ) {}

    public function notFound(): bool
    {
        return $this->status === 404;
    }

    public function failed(): bool
    {
        return $this->status >= 400;
    }
}
