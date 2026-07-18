<?php

declare(strict_types=1);

namespace App\Data\Project;

readonly class ProjectIdentity
{
    public function __construct(
        public string $slug,
        public string $defaultDomain,
    ) {}
}
