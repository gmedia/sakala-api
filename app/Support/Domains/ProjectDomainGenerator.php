<?php

declare(strict_types=1);

namespace App\Support\Domains;

class ProjectDomainGenerator
{
    public function __construct(
        protected string $baseDomain
    ) {}

    public function generate(string $slug): string
    {
        return "{$slug}.{$this->baseDomain}";
    }
}
