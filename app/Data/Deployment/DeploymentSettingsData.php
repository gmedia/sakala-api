<?php

declare(strict_types=1);

namespace App\Data\Deployment;

use App\Enums\AgentCommandType;
use App\Models\AgentCommand;
use App\Models\Deployment;
use App\Models\Project;
use App\Support\Runtime\RuntimeFeatures;

/**
 * The settings a deployment runs with that the project stores and may change
 * later: the root directory, the build, start, and release commands, the
 * port, and the worker processes (ADR-022, only while
 * RuntimeFeatures::workersEnabled()). Normally the project's (fromProject()); a rollback, and a redeploy of
 * a rollback, run with what an earlier deployment recorded (recordedBy()).
 * An empty command counts as none, as in the payload.
 */
final readonly class DeploymentSettingsData
{
    /** Payload keys of the DeployProject command that hold the settings. */
    private const ROOT_DIRECTORY_KEY = 'root_directory';

    private const BUILD_COMMAND_KEY = 'build_command';

    private const START_COMMAND_KEY = 'start_command';

    private const RELEASE_COMMAND_KEY = 'release_command';

    private const CONTAINER_PORT_KEY = 'container_port';

    private const PROCESSES_KEY = 'processes';

    /**
     * @param  list<WorkerProcessData>  $processes
     */
    private function __construct(
        public ?string $rootDirectory,
        public ?string $buildCommand,
        public ?string $startCommand,
        public ?string $releaseCommand,
        public int $containerPort,
        public array $processes = [],
    ) {}

    public static function fromProject(Project $project): self
    {
        return new self(
            rootDirectory: self::presentOrNull($project->root_directory),
            buildCommand: self::presentOrNull($project->build_command),
            startCommand: self::presentOrNull($project->start_command),
            releaseCommand: self::presentOrNull($project->release_command),
            containerPort: $project->effectivePort(),
            processes: RuntimeFeatures::workersEnabled()
                ? array_values($project->processes()->get()->map(WorkerProcessData::fromModel(...))->all())
                : [],
        );
    }

    /**
     * What `$deployment` ran with, from the payload of its DeployProject
     * command (the agent got exactly that) and its `container_port`. Null
     * when it has no such command, which only rows that never went through
     * CreateDeploymentAction lack: commands are kept until the project is
     * purged.
     */
    public static function recordedBy(Deployment $deployment): ?self
    {
        $command = AgentCommand::query()
            ->where('deployment_id', $deployment->id)
            ->where('type', AgentCommandType::DeployProject)
            ->orderBy('created_at')
            ->first(['id', 'payload']);

        if ($command === null) {
            return null;
        }

        $payload = $command->payload ?? [];
        $payloadPort = $payload[self::CONTAINER_PORT_KEY] ?? null;
        $port = $deployment->container_port ?? (is_int($payloadPort) ? $payloadPort : null);

        if ($port === null) {
            return null;
        }

        return new self(
            rootDirectory: self::stringOrNull($payload[self::ROOT_DIRECTORY_KEY] ?? null),
            buildCommand: self::stringOrNull($payload[self::BUILD_COMMAND_KEY] ?? null),
            startCommand: self::stringOrNull($payload[self::START_COMMAND_KEY] ?? null),
            releaseCommand: self::stringOrNull($payload[self::RELEASE_COMMAND_KEY] ?? null),
            containerPort: $port,
            processes: RuntimeFeatures::workersEnabled() ? self::recordedProcesses($payload[self::PROCESSES_KEY] ?? null) : [],
        );
    }

    public function hasReleaseCommand(): bool
    {
        return $this->releaseCommand !== null;
    }

    /**
     * Whether the deployment carries worker definitions, and so needs a node
     * with AgentNode::WORKER_PROCESSES_CAPABILITY. Definitions with zero
     * replicas count too: the field is only ever sent to such a node.
     */
    public function hasWorkerProcesses(): bool
    {
        return $this->processes !== [];
    }

    /** Worker containers the deployment runs: the sum of its processes' replicas. */
    public function workerContainers(): int
    {
        return array_sum(array_map(static fn (WorkerProcessData $process): int => $process->replicas, $this->processes));
    }

    /**
     * The optional payload keys, absent when unset, as protocol revision 4
     * expects.
     *
     * @return array<string, string>
     */
    public function toPayload(): array
    {
        return array_filter([
            self::BUILD_COMMAND_KEY => $this->buildCommand,
            self::START_COMMAND_KEY => $this->startCommand,
            self::RELEASE_COMMAND_KEY => $this->releaseCommand,
            self::ROOT_DIRECTORY_KEY => $this->rootDirectory,
        ], static fn (?string $value): bool => $value !== null);
    }

    /**
     * The optional `processes` key, absent without workers as protocol
     * revision 4 expects for agents that predate it.
     *
     * @return array{processes?: list<array<string, mixed>>}
     */
    public function processesPayload(): array
    {
        if ($this->processes === []) {
            return [];
        }

        return [self::PROCESSES_KEY => array_map(static fn (WorkerProcessData $process): array => $process->toPayload(), $this->processes)];
    }

    /** @return list<WorkerProcessData> */
    private static function recordedProcesses(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(WorkerProcessData::fromPayload(...), $items)));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? self::presentOrNull($value) : null;
    }

    private static function presentOrNull(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
