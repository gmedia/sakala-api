<?php

declare(strict_types=1);

namespace App\Data\Agent;

use App\Enums\AgentNodeDesiredState;

final readonly class AgentNodeStateData
{
    public function __construct(
        public AgentNodeDesiredState $desiredState,
    ) {}
}
