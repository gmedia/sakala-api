<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\AgentAuthStatus;
use App\Enums\AgentNodeStatus;

final readonly class AdminAgentFilterData
{
    public function __construct(
        public int $page,
        public int $perPage,
        public ?string $search = null,
        public ?AgentNodeStatus $status = null,
        public ?AgentAuthStatus $authStatus = null,
        public ?bool $neverSeen = null,
    ) {}
}
