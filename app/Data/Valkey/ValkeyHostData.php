<?php

declare(strict_types=1);

namespace App\Data\Valkey;

/** What a Valkey node reported about its role in its last heartbeat (`metadata.valkey`, ADR-023). */
final readonly class ValkeyHostData
{
    public function __construct(
        /** `SAKALA_VALKEY_TOTAL_MEMORY_MB`: the bound of Σ(maxmemory_mb + 64) of its stores. */
        public int $capacityMb,
        /** Whether the agent's last reading of its stores worked. */
        public bool $available,
    ) {}
}
