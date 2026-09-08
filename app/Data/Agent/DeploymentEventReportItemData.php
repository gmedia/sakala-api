<?php

declare(strict_types=1);

namespace App\Data\Agent;

use App\Enums\DeploymentEventLevel;
use Carbon\CarbonImmutable;

final readonly class DeploymentEventReportItemData
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public DeploymentEventLevel $level,
        public string $type,
        public string $message,
        public ?array $metadata,
        public CarbonImmutable $occurredAt,
    ) {}
}
