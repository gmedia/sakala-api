<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deployment;

use App\Actions\Deployment\CreateDeploymentAction;
use App\Actions\Deployment\GetDeploymentCollectionAction;
use App\Actions\Deployment\GetDeploymentEventAction;
use App\Actions\Deployment\GetDeploymentLogAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Deployment\IndexDeploymentRequest;
use App\Http\Requests\Api\V1\Deployment\PaginateDeploymentRequest;
use App\Http\Requests\Api\V1\Deployment\StoreDeploymentRequest;
use App\Http\Resources\Api\V1\Deployment\DeploymentEventResource;
use App\Http\Resources\Api\V1\Deployment\DeploymentLogResource;
use App\Http\Resources\Api\V1\Deployment\DeploymentResource;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class DeploymentController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
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

    public function logs(
        PaginateDeploymentRequest $request,
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
