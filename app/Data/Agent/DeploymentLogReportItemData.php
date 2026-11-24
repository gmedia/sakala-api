<?php

declare(strict_types=1);

namespace App\Data\Agent;

use App\Enums\LogStream;
use Carbon\CarbonImmutable;

final readonly class DeploymentLogReportItemData
{
    public function __construct(
        public LogStream $stream,
        public string $message,
        public CarbonImmutable $recordedAt,
        /** The worker name or `job` the line came from (ADR-022); null for build, release, and web lines. */
        public ?string $process = null,
    ) {}
}
