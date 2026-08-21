<?php

declare(strict_types=1);

namespace App\Data\Agent;

use App\Enums\AgentStatus;
use Carbon\CarbonImmutable;

final readonly class AgentData
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public string $tokenPrefix,
        public AgentStatus $status,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $updatedAt,
    ) {}
}
