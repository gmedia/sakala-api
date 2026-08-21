<?php

namespace App\Data\Agent;

use App\Enums\AgentNodeStatus;
use Illuminate\Support\Carbon;

final readonly class AgentHeartbeatData
{
    /**
     * @param array<int, string> $capabilities
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public AgentNodeStatus $status,
        public string $hostname,
        public string $runtimeNetwork,
        public array $capabilities,
        public array $metadata,
        public Carbon $sentAt,
    ) {}
}