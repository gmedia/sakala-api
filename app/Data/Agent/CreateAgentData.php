<?php

declare(strict_types=1);

namespace App\Data\Agent;

final readonly class CreateAgentData
{
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {}
}