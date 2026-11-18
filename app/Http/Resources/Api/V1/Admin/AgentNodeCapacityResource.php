<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Data\Agent\NodeCapacityData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a node had free at its last heartbeat. Every value is null when the
 * node did not report it.
 *
 * @property NodeCapacityData $resource
 */
final class AgentNodeCapacityResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /** @return array<string, int|null> */
    public function toArray(Request $request): array
    {
        $capacity = $this->resource;

        return [
            'memory_total_bytes' => $capacity->memoryTotalBytes,
            'memory_available_bytes' => $capacity->memoryAvailableBytes,
            /** Containers the node runs. */
            'active_workloads' => $capacity->activeWorkloads,
            /** The most containers the node runs at once (`SAKALA_MAX_ACTIVE_CONTAINERS`). */
            'maximum_active_workloads' => $capacity->maximumActiveWorkloads,
            /** `maximum_active_workloads` minus `active_workloads`, never below 0. */
            'free_workload_slots' => $capacity->freeWorkloadSlots(),
        ];
    }
}
