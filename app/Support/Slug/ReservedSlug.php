<?php

declare(strict_types=1);

namespace App\Support\Slug;

class ReservedSlug
{
    /**
     * @param  list<string>  $reservedSlugs
     */
    public function __construct(
        protected array $reservedSlugs,
    ) {}

    public function isReserved(string $slug): bool
    {
        return in_array($slug, $this->reservedSlugs, true);
    }
}
