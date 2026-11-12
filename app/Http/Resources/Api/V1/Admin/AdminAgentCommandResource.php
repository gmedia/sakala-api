<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\AgentCommand;
use App\Models\AgentCommandReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A command a node received. The payload and contexts are never included:
 * a deployment payload carries the project's environment variables.
 *
 * @mixin AgentCommand
 *
 * @property AgentCommand $resource
 */
final class AdminAgentCommandResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $command = $this->resource;
        $project = $command->project;
        $end = $command->completed_at ?? $command->failed_at;
        $start = $command->started_at ?? $command->claimed_at;

        return [
            'id' => $command->id,
            /** An `AgentCommandType`, for example `DeployProject`, `StopProject`, `CleanupRuntime`. */
            'type' => $command->type->value,
            /** `Pending`, `Claimed`, `Running`, `Succeeded`, `Failed`, `Cancelled`, or `Expired`. */
            'status' => $command->status->value,
            /**
             * Null for node-level commands such as `DrainNode` and `CleanupRuntime`.
             */
            'project' => $project === null ? null : [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'deleted' => $project->trashed(),
            ],
            /**
             * Set for `DeployProject`; its output is read through the admin deployment logs endpoint.
             *
             * @var string|null
             */
            'deployment_id' => $command->getAttribute('deployment_id'),
            'attempts' => $command->attempts,
            /** @var string|null */
            'error_code' => $command->getAttribute('error_code'),
            /**
             * The agent's failure message, redacted before it was stored.
             *
             * @var string|null
             */
            'error_message' => $command->getAttribute('error_message'),
            /** @var string|null */
            'created_at' => $command->created_at->toAtomString(),
            'claimed_at' => $command->claimed_at?->toAtomString(),
            'started_at' => $command->started_at?->toAtomString(),
            'completed_at' => $command->completed_at?->toAtomString(),
            'failed_at' => $command->failed_at?->toAtomString(),
            'cancel_requested_at' => $command->cancel_requested_at?->toAtomString(),
            /**
             * From claim to completion or failure, in seconds.
             *
             * @var int|null
             */
            'duration_seconds' => $start === null || $end === null ? null : max(0, $end->getTimestamp() - $start->getTimestamp()),
            /**
             * Events and logs the node reported for a command without a deployment.
             *
             * @var int|null
             */
            'reports_count' => $this->whenCounted('reports'),
            /**
             * Present on the command detail only: up to the 200 newest reports, oldest first.
             *
             * @var list<array{sequence: int, kind: string, level: string|null, stream: string|null, type: string|null, message: string, metadata: array<string, mixed>|null, occurred_at: string}>
             */
            'reports' => $this->whenLoaded('reports', fn (): array => $command->reports
                ->map(fn (AgentCommandReport $report): array => [
                    'sequence' => $report->sequence,
                    'kind' => $report->kind->value,
                    'level' => $report->level?->value,
                    'stream' => $report->stream?->value,
                    'type' => $report->type,
                    'message' => $report->message,
                    'metadata' => $report->metadata === null ? null : (object) $report->metadata,
                    'occurred_at' => $report->occurred_at->toAtomString(),
                ])
                ->values()
                ->all()),
        ];
    }
}
