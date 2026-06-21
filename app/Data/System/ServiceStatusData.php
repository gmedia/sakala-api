<?php

declare(strict_types=1);

namespace App\Data\System;

final readonly class ServiceStatusData
{
    public function __construct(
        public string $service,
        public string $status,
        public string $apiVersion,
    ) {}
}
