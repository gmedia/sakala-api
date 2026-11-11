<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\Project;
use App\Models\ProjectControlRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 *
 * @property Project $resource
 */
final class AdminProjectDetailResource extends JsonResource
{
    public const RECENT_CONTROL_REQUESTS = 20;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $project = $this->resource;

        return [
            'project' => new AdminProjectResource($project),
            'deployments_count' => (int) $project->getAttribute('deployments_count'),
            /** The `PORT` the next deployment gets: `configured_port`, then `detected_port`, then 3000. */
            'effective_port' => $project->effectivePort(),
            /** @var string|null */
            'inspection_status' => $project->inspection_status?->value,
            /**
             * The newest admin control requests (stop, suspend, unsuspend, reconcile), with the admin's reason.
             *
             * @var list<array{id: int, action: string, reason: string, actor_id: string, agent_command_id: string|null, created_at: string|null}>
             */
            'control_requests' => $project->controlRequests
                ->map(fn (ProjectControlRequest $controlRequest): array => [
                    'id' => (int) $controlRequest->getKey(),
                    'action' => $controlRequest->action->value,
                    'reason' => $controlRequest->reason,
                    'actor_id' => (string) $controlRequest->actor_id,
                    'agent_command_id' => $controlRequest->agent_command_id,
                    'created_at' => $controlRequest->created_at?->toAtomString(),
                ])
                ->values()
                ->all(),
        ];
    }
}
