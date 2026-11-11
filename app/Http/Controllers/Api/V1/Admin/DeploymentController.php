<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\GetFailedDeploymentCollectionAction;
use App\Actions\Admin\GetFailureSummaryAction;
use App\Actions\Deployment\GetDeploymentEventAction;
use App\Actions\Deployment\GetDeploymentLogAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminDeploymentEventRequest;
use App\Http\Requests\Api\V1\Admin\AdminDeploymentLogRequest;
use App\Http\Requests\Api\V1\Admin\FailureSummaryRequest;
use App\Http\Requests\Api\V1\Admin\IndexFailedDeploymentRequest;
use App\Http\Resources\Api\V1\Admin\AdminDeploymentResource;
use App\Http\Resources\Api\V1\Admin\FailedDeploymentResource;
use App\Http\Resources\Api\V1\Admin\FailureSummaryResource;
use App\Http\Resources\Api\V1\Deployment\DeploymentEventResource;
use App\Http\Resources\Api\V1\Deployment\DeploymentLogResource;
use App\Models\Deployment;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class DeploymentController extends Controller
{
    /**
     * Failed deployments across every project, newest first.
     */
    public function failures(IndexFailedDeploymentRequest $request, GetFailedDeploymentCollectionAction $action): AnonymousResourceCollection
    {
        return FailedDeploymentResource::collection($action->handle($request->toData()));
    }

    /**
     * Failed deployments in a range by category and code, with the previous window for trends.
     */
    public function failureSummary(FailureSummaryRequest $request, GetFailureSummaryAction $action): FailureSummaryResource
    {
        return new FailureSummaryResource($action->handle($request->toData()));
    }

    /**
     * Any deployment, with its project, requester, and node.
     */
    public function show(Deployment $deployment): AdminDeploymentResource
    {
        $deployment->load([
            'project' => fn ($query) => $query->withTrashed(),
            'requester:id,name,username',
            'agentNode:id,agent_id,name',
        ]);

        return new AdminDeploymentResource($deployment);
    }

    /**
     * Timeline events of any deployment, as members read them. Metadata is already redacted.
     */
    public function events(AdminDeploymentEventRequest $request, Deployment $deployment, GetDeploymentEventAction $action): AnonymousResourceCollection
    {
        return DeploymentEventResource::collection($action->handle(deployment: $deployment, data: $request->toData()));
    }

    /**
     * Log lines of any deployment, as members read them. Lines are redacted before they are stored.
     *
     * Oldest first by default; `order=desc` starts from the newest line.
     */
    public function logs(AdminDeploymentLogRequest $request, Deployment $deployment, GetDeploymentLogAction $action): AnonymousResourceCollection
    {
        return DeploymentLogResource::collection($action->handle(deployment: $deployment, data: $request->toData()));
    }
}
