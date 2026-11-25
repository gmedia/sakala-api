<?php

declare(strict_types=1);

namespace App\Data\CustomDomain;

/** One CAA record (RFC 8659): flags, tag, and value as published. */
final readonly class CaaRecordData
{
    /** The issuer-critical flag: a CA must not issue when it does not understand the tag. */
    public const CRITICAL_FLAG = 128;

    public function __construct(
        public int $flags,
        public string $tag,
        public string $value,
    ) {}

    public function isCritical(): bool
    {
        return ($this->flags & self::CRITICAL_FLAG) === self::CRITICAL_FLAG;
    }
}
