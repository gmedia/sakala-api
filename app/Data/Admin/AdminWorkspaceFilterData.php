<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\WorkspaceType;

final readonly class AdminWorkspaceFilterData
{
    public function __construct(
        public int $page,
        public int $perPage,
        public ?string $search = null,
        public ?WorkspaceType $type = null,
        public ?string $limitProfileId = null,
    ) {}
}
