<?php

declare(strict_types=1);

namespace App\Support\Domains;

/**
 * Every project gets `<slug>.<base domain>`. The base domain is a registrable
 * domain apart from the API and console (ADR-016), so a tenant application is
 * never the same site as Sakala itself.
 */
class ProjectDomainGenerator
{
    /** Hosted Sakala's base domain, the default of `SAKALA_PROJECT_DEFAULT_DOMAIN`. */
    public const DEFAULT_BASE_DOMAIN = 'sakala.app';

    public function __construct(
        protected string $baseDomain
    ) {}

    public function generate(string $slug): string
    {
        return "{$slug}.{$this->baseDomain}";
    }
}
