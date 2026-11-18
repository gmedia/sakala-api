<?php

declare(strict_types=1);

namespace App\Data\Edge;

/** What one `edge:check-health` run found. */
final readonly class EdgeHealthCheckResultData
{
    public function __construct(
        public int $checked,
        public int $becameUnhealthy,
        public int $recovered,
    ) {}
}
