<?php

declare(strict_types=1);

namespace App\Data\Project;

/**
 * A worker definition as a member saves it. On update, only the fields in
 * `$provided` change.
 */
final readonly class ProjectProcessData
{
    /**
     * @param  list<string>  $provided  The fields the request sent.
     */
    public function __construct(
        public ?string $name,
        public ?string $command,
        public ?int $replicas,
        public ?int $memoryMb,
        public ?int $cpuMillis,
        public array $provided,
    ) {}

    /**
     * The model attributes the request sent.
     *
     * @return array<string, int|string|null>
     */
    public function attributes(): array
    {
        return array_intersect_key([
            'name' => $this->name,
            'command' => $this->command,
            'replicas' => $this->replicas,
            'memory_mb' => $this->memoryMb,
            'cpu_millis' => $this->cpuMillis,
        ], array_flip($this->provided));
    }
}
