<?php

declare(strict_types=1);

namespace App\Data\Agent;

use App\Enums\AgentNodeStatus;
use Carbon\CarbonImmutable;

final readonly class AgentHeartbeatData
{
    /**
     * @param  array<int, string>  $capabilities
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $hostname,
        public string $runtimeNetwork,
        public array $capabilities,
        public array $metadata,
        public AgentNodeStatus $status,
        public CarbonImmutable $sentAt,
    ) {}
}
