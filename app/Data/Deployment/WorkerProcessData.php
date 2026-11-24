<?php

declare(strict_types=1);

namespace App\Data\Deployment;

use App\Models\ProjectProcess;

/**
 * One worker process as a deployment runs it (ADR-022): what DeployProject
 * carries in `processes`, and what a rollback reads back from the target's
 * payload. `resources` fields left null take the deployment's own values on
 * the node; `pids_limit` is never set per worker.
 */
final readonly class WorkerProcessData
{
    /** Payload keys of one item of `DeployProject.processes`. */
    private const NAME_KEY = 'name';

    private const COMMAND_KEY = 'command';

    private const REPLICAS_KEY = 'replicas';

    private const RESOURCES_KEY = 'resources';

    private const MEMORY_KEY = 'memory_mb';

    private const CPU_KEY = 'cpu_millis';

    private const PIDS_KEY = 'pids_limit';

    public function __construct(
        public string $name,
        public string $command,
        public int $replicas,
        public ?int $memoryMb = null,
        public ?int $cpuMillis = null,
    ) {}

    public static function fromModel(ProjectProcess $process): self
    {
        return new self(
            name: $process->name,
            command: $process->command,
            replicas: $process->replicas,
            memoryMb: $process->memory_mb,
            cpuMillis: $process->cpu_millis,
        );
    }

    /**
     * One recorded payload item, or null when it is not one the API wrote.
     * The API only ever records valid items, so null means a row from
     * outside CreateDeploymentAction and is left out.
     */
    public static function fromPayload(mixed $item): ?self
    {
        if (! is_array($item)
            || ! is_string($item[self::NAME_KEY] ?? null)
            || ! is_string($item[self::COMMAND_KEY] ?? null)
            || ! is_int($item[self::REPLICAS_KEY] ?? null)) {
            return null;
        }

        $resources = is_array($item[self::RESOURCES_KEY] ?? null) ? $item[self::RESOURCES_KEY] : [];
        $integer = static fn (mixed $value): ?int => is_int($value) ? $value : null;

        return new self(
            name: $item[self::NAME_KEY],
            command: $item[self::COMMAND_KEY],
            replicas: $item[self::REPLICAS_KEY],
            memoryMb: $integer($resources[self::MEMORY_KEY] ?? null),
            cpuMillis: $integer($resources[self::CPU_KEY] ?? null),
        );
    }

    /**
     * @return array{name: string, command: string, replicas: int, resources: array{memory_mb: int|null, cpu_millis: int|null, pids_limit: null}}
     */
    public function toPayload(): array
    {
        return [
            self::NAME_KEY => $this->name,
            self::COMMAND_KEY => $this->command,
            self::REPLICAS_KEY => $this->replicas,
            self::RESOURCES_KEY => [
                self::MEMORY_KEY => $this->memoryMb,
                self::CPU_KEY => $this->cpuMillis,
                self::PIDS_KEY => null,
            ],
        ];
    }
}
