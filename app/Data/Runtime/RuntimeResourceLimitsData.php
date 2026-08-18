<?php

declare(strict_types=1);

namespace App\Data\Runtime;

final readonly class RuntimeResourceLimitsData
{
    public function __construct(
        public ?int $memory_mb = null,
        public ?int $cpu_millis = null,
        public ?int $pids_limit = null,
    ) {}

    /**
     * @return array{memory_mb: int|null, cpu_millis: int|null, pids_limit: int|null}
     */
    public function toArray(): array
    {
        return [
            'memory_mb' => $this->memory_mb,
            'cpu_millis' => $this->cpu_millis,
            'pids_limit' => $this->pids_limit,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            memory_mb: isset($data['memory_mb']) ? (int) $data['memory_mb'] : null,
            cpu_millis: isset($data['cpu_millis']) ? (int) $data['cpu_millis'] : null,
            pids_limit: isset($data['pids_limit']) ? (int) $data['pids_limit'] : null,
        );
    }
}
