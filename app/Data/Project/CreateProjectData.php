<?php

declare(strict_types=1);

namespace App\Data\Project;

final readonly class CreateProjectData
{
    public function __construct(
        public string $name,
        public string $repository_url,
        public string $branch,
    ) {}
}
