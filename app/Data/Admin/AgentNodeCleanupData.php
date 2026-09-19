<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\RuntimeCleanupTarget;

final readonly class AgentNodeCleanupData
{
    /**
     * @param  list<RuntimeCleanupTarget>  $targets
     */
    public function __construct(
        public string $reason,
        public array $targets,
        public ?string $idempotencyKey = null,
    ) {}
}
