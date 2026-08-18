<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deployment;

use App\Actions\Deployment\CreateDeploymentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Deployment\StoreDeploymentRequest;
use App\Http\Resources\Api\V1\Deployment\CreateDeploymentResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

class DeploymentController extends Controller
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

        return (new CreateDeploymentResource($deployment))
            ->response()
            ->setStatusCode(201);
    }
}
