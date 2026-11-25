<?php

declare(strict_types=1);

namespace App\Data\Agent;

use App\Data\Valkey\ValkeyUsageData;
use App\Enums\AgentNodeStatus;
use Carbon\CarbonImmutable;

final readonly class AgentHeartbeatData
{
    /**
     * @param  array<int, string>  $capabilities
     * @param  array<string, mixed>  $metadata
     * @param  array<string, ValkeyUsageData>|null  $valkeyUsage  Store readings keyed by lowercase ID; null when the heartbeat carries none.
     */
    public function __construct(
        public string $hostname,
        public string $runtimeNetwork,
        public array $capabilities,
        public array $metadata,
        public AgentNodeStatus $status,
        public int $protocolVersion,
        public CarbonImmutable $sentAt,
        public ?CrashedWorkloadsData $crashedWorkloads = null,
        public ?WorkloadTrafficData $workloadTraffic = null,
        public ?WorkloadReplicasData $workloadReplicas = null,
        public ?WorkloadMetricsData $workloadMetrics = null,
        public ?DatabaseSizesData $databaseSizes = null,
        public ?BucketSizesData $bucketSizes = null,
        public ?array $valkeyUsage = null,
    ) {}
}
