<?php

declare(strict_types=1);

namespace App\Data\CustomDomain;

use App\Enums\CustomDomainCheckReason;
use App\Enums\CustomDomainRoutingMatch;

/**
 * What one DNS check of a claim found (CustomDomainChecker). It passes only
 * when ownership, pointing, and CAA all pass; `reasons` lists every failure.
 */
final readonly class CustomDomainCheckResultData
{
    /**
     * @param  list<CustomDomainCheckReason>  $reasons
     */
    public function __construct(
        public bool $ownership,
        public bool $routing,
        public bool $caa,
        public array $reasons,
        public ?CustomDomainRoutingMatch $routingMatch,
    ) {}

    public function passed(): bool
    {
        return $this->reasons === [] && $this->ownership && $this->routing && $this->caa;
    }

    /** @return list<string> */
    public function reasonValues(): array
    {
        return array_map(static fn (CustomDomainCheckReason $reason): string => $reason->value, $this->reasons);
    }

    /** The same result, failed because another project holds the name. */
    public function taken(): self
    {
        return new self($this->ownership, $this->routing, $this->caa, [...$this->reasons, CustomDomainCheckReason::DomainTaken], $this->routingMatch);
    }
}
