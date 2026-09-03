<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Project;

use App\Actions\Project\CreateEnvironmentVariableAction;
use App\Actions\Project\DeleteEnvironmentVariableAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Project\EnvironmentVariableRequest;
use App\Http\Resources\Api\V1\Project\EnvironmentVariableResource;
use App\Http\Resources\Api\V1\Project\EnvironmentVariableValueResource;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class EnvironmentVariableController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Project $project): AnonymousResourceCollection
    {
        $this->authorize('view', $project);

        return EnvironmentVariableResource::collection(
            $project->environmentVariables()
                ->latest()
                ->get()
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(
        Project $project,
        EnvironmentVariableRequest $request,
        CreateEnvironmentVariableAction $action
    ): JsonResponse {
        $environmentVariable = $action->handle(
            $request->toData()
        );

        return (new EnvironmentVariableResource($environmentVariable))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(
        Project $project,
        DeleteEnvironmentVariableAction $action,
        EnvironmentVariable $environmentVariable
    ): Response {
        $this->authorize('update', $project);

        $action->handle($environmentVariable);

        return response()->noContent();
    }

    public function value(
        Project $project,
        EnvironmentVariable $environmentVariable,
    ): JsonResponse {
        $this->authorize('view', $project);

        return (new EnvironmentVariableValueResource($environmentVariable))
            ->response();
    }
}
