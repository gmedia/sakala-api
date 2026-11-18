<?php

declare(strict_types=1);

namespace App\Data\Agent;

/**
 * What a node had free at its last heartbeat: `metadata.resources` memory and
 * `metadata.workloads` container counts. Every value is null when the node did
 * not report it (telemetry unavailable, or an agent that does not send it).
 */
final readonly class NodeCapacityData
{
    public function __construct(
        public ?int $memoryTotalBytes,
        public ?int $memoryAvailableBytes,
        /** Containers the node runs now. */
        public ?int $activeWorkloads,
        /** The most containers the node runs at once (`SAKALA_MAX_ACTIVE_CONTAINERS`). */
        public ?int $maximumActiveWorkloads,
    ) {}

    /**
     * Containers the node could still start, never below zero. Null unless the
     * node reported both its running count and its maximum.
     */
    public function freeWorkloadSlots(): ?int
    {
        if ($this->activeWorkloads === null || $this->maximumActiveWorkloads === null) {
            return null;
        }

        return max(0, $this->maximumActiveWorkloads - $this->activeWorkloads);
    }
}
