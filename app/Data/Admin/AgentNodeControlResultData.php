<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Models\AgentCommand;
use App\Models\AgentNode;

final readonly class AgentNodeControlResultData
{
    public function __construct(
        public AgentNode $node,
        public AgentCommand $command,
    ) {}
}
