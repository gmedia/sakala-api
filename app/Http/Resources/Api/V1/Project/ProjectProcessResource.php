<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Project;

use App\Models\ProjectProcess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A worker process definition (ADR-022), with what the serving deployment
 * reports about it.
 *
 * @mixin ProjectProcess
 *
 * @property ProjectProcess $resource
 */
final class ProjectProcessResource extends JsonResource
{
    /** @var array<string, array<string, mixed>>|null Crash details of the serving deployment's workers, keyed by name. */
    public ?array $crashes = null;

    public ?bool $redeployRequired = null;

    /** @param  array<string, array<string, mixed>>|null  $crashes */
    public function withCrashes(?array $crashes): static
    {
        $this->crashes = $crashes;

        return $this;
    }

    public function withRedeployRequired(bool $required): static
    {
        $this->redeployRequired = $required;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $process = $this->resource;
        $crash = $this->crashes[$process->name] ?? null;

        return [
            'id' => $process->id,
            'name' => $process->name,
            'command' => $process->command,
            /** Containers of this process; `0` keeps the definition and runs nothing. */
            'replicas' => $process->replicas,
            /** Per container, in MB; `null` follows the deployment's. */
            'memory_mb' => $process->memory_mb,
            /** Per container, in thousandths of a CPU; `null` follows the deployment's. */
            'cpu_millis' => $process->cpu_millis,
            /**
             * Set while the node reports this worker of the serving deployment as crashing (exited with a code other
             * than 0, or restarting over and over); `null` otherwise. A crashing worker never marks the web process
             * crashed. `running` true means it is up between two crashes.
             *
             * @var array{exit_code: int|null, oom_killed: bool, finished_at: string|null, restart_count: int|null, running: bool|null, since: string}|null
             */
            'crash' => $crash,
            /** Only on the answer to a change: whether the serving deployment now differs from the project's settings. */
            'redeploy_required' => $this->when($this->redeployRequired !== null, fn (): bool => (bool) $this->redeployRequired),
            'created_at' => $process->created_at?->toAtomString(),
            'updated_at' => $process->updated_at?->toAtomString(),
        ];
    }
}
