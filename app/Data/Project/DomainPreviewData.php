<?php

declare(strict_types=1);

namespace App\Data\Project;

final readonly class DomainPreviewData
{
    public function __construct(
        public string $slug,
        public string $domain,
    ) {}
}
