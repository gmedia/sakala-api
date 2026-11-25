<?php

declare(strict_types=1);

namespace App\Data\CustomDomain;

use App\Enums\CustomDomainKind;

/** A custom domain in its final form (CustomDomainNameParser). */
final readonly class ParsedCustomDomainData
{
    public function __construct(
        public string $hostname,
        public CustomDomainKind $kind,
    ) {}
}
