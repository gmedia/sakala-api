<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Project;

use App\Actions\Project\GetProjectMetricsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Project\ProjectMetricsRequest;
use App\Http\Resources\Api\V1\Project\ProjectMetricsResource;
use App\Models\Project;

final class ProjectMetricsController extends Controller
{
    /**
     * CPU, memory, replica, and restart series of a project, summed across its replicas.
     *
     * 1h, 6h, and 24h come from per-minute rows, 7d and 30d from hourly roll-ups. Empty series mean the project's
     * node has not reported metrics for the range.
     */
    public function __invoke(
        Project $project,
        ProjectMetricsRequest $request,
        GetProjectMetricsAction $action,
    ): ProjectMetricsResource {
        return new ProjectMetricsResource(
            $action->handle($project, $request->range(), $request->perReplica()),
        );
    }
}
