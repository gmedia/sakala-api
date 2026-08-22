<?php

declare(strict_types=1);

namespace App\Data\Agent;

use App\Enums\AgentAuthStatus;
use App\Enums\AgentNodeStatus;
use Carbon\CarbonImmutable;

final readonly class AgentNodeData
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public string $tokenPrefix,
        public AgentAuthStatus $authStatus,
        public AgentNodeStatus $status,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $updatedAt,
        public ?CarbonImmutable $registeredAt,
    ) {}
}
