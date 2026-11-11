<?php

declare(strict_types=1);

namespace App\Data\Admin;

/** The values of a limit profile; on update, null fields stay unchanged. */
final readonly class LimitProfileData
{
    /** @param  array<string, int|string|null>  $attributes  Only the fields the admin sent. */
    public function __construct(
        public array $attributes,
        public string $reason,
    ) {}
}
