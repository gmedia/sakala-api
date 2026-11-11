<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\UserRole;

final readonly class AdminUserFilterData
{
    public function __construct(
        public int $page,
        public int $perPage,
        public ?string $search = null,
        public ?UserRole $role = null,
        public ?bool $verified = null,
    ) {}
}
