<?php

declare(strict_types=1);

namespace App\Data\Project;

/** A project `projects:move-domain` left alone, because its domain is not `<slug>.<from>`. */
final readonly class UnexpectedProjectDomainData
{
    public function __construct(
        public string $projectId,
        public string $slug,
        public string $defaultDomain,
        public bool $isDeleted,
    ) {}
}
