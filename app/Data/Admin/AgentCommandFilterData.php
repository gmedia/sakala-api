<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;

final readonly class AgentCommandFilterData
{
    public function __construct(
        public int $page,
        public int $perPage,
        public ?AgentCommandType $type = null,
        public ?AgentCommandStatus $status = null,
    ) {}
}
