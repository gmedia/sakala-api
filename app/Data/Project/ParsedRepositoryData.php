<?php

declare(strict_types=1);

namespace App\Data\Project;

final readonly class ParsedRepositoryData
{
    public function __construct(
        public string $repository_provider,
        public string $repository_full_name,
        public string $repository_url,
    ) {}
}
