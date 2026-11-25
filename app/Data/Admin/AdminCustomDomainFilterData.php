<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\CustomDomainStatus;

final readonly class AdminCustomDomainFilterData
{
    public function __construct(
        public int $page,
        public int $perPage,
        public ?string $search = null,
        public ?CustomDomainStatus $status = null,
        public ?string $projectId = null,
        public ?string $workspaceId = null,
        public ?bool $failing = null,
    ) {}
}
