<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;

final readonly class AdminProjectFilterData
{
    public function __construct(
        public int $page,
        public int $perPage,
        public ?string $search = null,
        public ?ProjectStatus $status = null,
        public ?RuntimeStatus $runtimeStatus = null,
        public ?string $workspaceId = null,
        public ?bool $suspended = null,
    ) {}
}
