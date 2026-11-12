<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deployment;

use App\Actions\Deployment\CancelDeploymentAction;
use App\Actions\Deployment\CreateDeploymentAction;
use App\Actions\Deployment\GetDeploymentCollectionAction;
use App\Actions\Deployment\GetDeploymentEventAction;
use App\Actions\Deployment\GetDeploymentLogAction;
use App\Enums\DeploymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Deployment\IndexDeploymentLogRequest;
use App\Http\Requests\Api\V1\Deployment\IndexDeploymentRequest;
use App\Http\Requests\Api\V1\Deployment\PaginateDeploymentRequest;
use App\Http\Requests\Api\V1\Deployment\StoreDeploymentRequest;
use App\Http\Resources\Api\V1\Deployment\DeploymentEventResource;
use App\Http\Resources\Api\V1\Deployment\DeploymentLogResource;
use App\Http\Resources\Api\V1\Deployment\DeploymentResource;
use App\Models\Deployment;
use App\Models\Project;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class DeploymentController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */

    // Untuk Testing
    #[HeaderParameter(
        'Idempotency-Key',
        description: 'Unique key used to safely retry a deployment request.',
        type: 'string',
        example: '550e8400-e29b-41d4-a716-446655440000',
    )]
    public function store(
        StoreDeploymentRequest $request,
        Project $project,
        CreateDeploymentAction $action
    ): JsonResponse {
        $deployment = $action->handle(
            project: $project,
            user: $request->user(),
            data: $request->toData()
        );

        return (new DeploymentResource($deployment))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     *
     * @scramble-return DeploymentResource
     */
    public function show(
        Project $project,
        Deployment $deployment
    ): DeploymentResource {
        Gate::authorize('view', $project);

        return new DeploymentResource($deployment);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(
        IndexDeploymentRequest $request,
        Project $project,
        GetDeploymentCollectionAction $action,
    ): AnonymousResourceCollection {
        $deployments = $action->handle(
            project: $project,
            data: $request->toData(),
        );

        return DeploymentResource::collection($deployments);
    }

    /**
     * Cancel a deployment.
     *
     * Answers 200 once the deployment is `cancelled`, including on a repeat, and
     * 202 while a node still has to stop it. Answers 409
     * `DEPLOYMENT_ALREADY_FINISHED` when it already succeeded or failed.
     */
    #[OpenApiResponse(200, 'The deployment is `cancelled`.', type: 'array{data: DeploymentResource}')]
    #[OpenApiResponse(202, 'A node still has to stop the deployment.', type: 'array{data: DeploymentResource}')]
    public function cancel(
        Request $request,
        Project $project,
        Deployment $deployment,
        CancelDeploymentAction $action,
    ): JsonResponse {
        Gate::authorize('cancelDeployment', $project);

        $deployment = $action->handle($deployment, $request->user());

        return (new DeploymentResource($deployment))
            ->response()
            ->setStatusCode($deployment->status === DeploymentStatus::Cancelled ? 200 : 202);
    }

    public function events(
        PaginateDeploymentRequest $request,
        Project $project,
        Deployment $deployment,
        GetDeploymentEventAction $action
    ): AnonymousResourceCollection {
        Gate::authorize('view', $project);

        $events = $action->handle(
            deployment: $deployment,
            data: $request->toData(),
        );

        return DeploymentEventResource::collection($events);
    }

    /**
     * List deployment logs.
     *
     * Oldest first by default. `order=desc` starts from the newest line, and
     * the cursor then walks back through older ones.
     */
    public function logs(
        IndexDeploymentLogRequest $request,
        Project $project,
        Deployment $deployment,
        GetDeploymentLogAction $action
    ): AnonymousResourceCollection {
        Gate::authorize('view', $project);

        $logs = $action->handle(
            deployment: $deployment,
            data: $request->toData(),
        );

        return DeploymentLogResource::collection($logs);
    }
}
