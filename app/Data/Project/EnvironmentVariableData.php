<?php

declare(strict_types=1);

namespace App\Data\Project;

final readonly class EnvironmentVariableData
{
    public function __construct(
        public string $projectId,
        public string $key,
        public string $value,
        public bool $isSecret,
    ) {}
}
