<?php

declare(strict_types=1);

namespace App\Data\Admin;

final readonly class ProjectControlData
{
    public function __construct(
        public string $reason,
        public ?string $idempotencyKey = null
    ) {}
}
