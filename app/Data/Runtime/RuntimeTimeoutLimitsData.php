<?php

declare(strict_types=1);

namespace App\Data\Runtime;

final readonly class RuntimeTimeoutLimitsData
{
    public function __construct(
        public int $build_timeout_seconds,
        public int $start_timeout_seconds,
        public int $command_timeout_seconds,
    ) {}

    /**
     * @return array{build_timeout_seconds: int, start_timeout_seconds: int, command_timeout_seconds: int}
     */
    public function toArray(): array
    {
        return [
            'build_timeout_seconds' => $this->build_timeout_seconds,
            'start_timeout_seconds' => $this->start_timeout_seconds,
            'command_timeout_seconds' => $this->command_timeout_seconds,
        ];
    }
}
