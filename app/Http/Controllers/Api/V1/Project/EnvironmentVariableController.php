<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Project;

use App\Actions\Project\CreateEnvironmentVariableAction;
use App\Actions\Project\DeleteEnvironmentVariableAction;
use App\Actions\Project\RevealEnvironmentVariableValueAction;
use App\Actions\Project\UpdateEnvironmentVariableAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Project\EnvironmentVariableRequest;
use App\Http\Requests\Api\V1\Project\UpdateEnvironmentVariableRequest;
use App\Http\Resources\Api\V1\Project\EnvironmentVariableResource;
use App\Http\Resources\Api\V1\Project\EnvironmentVariableValueResource;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\User;
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
            ->withRedeployRequired((bool) $project->refresh()->requiresRedeploy())
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update a value or its secret flag. The running deployment keeps the old
     * value until the next deployment: see `redeploy_required`.
     *
     * @scramble-return EnvironmentVariableResource
     */
    public function update(
        Project $project,
        EnvironmentVariable $environmentVariable,
        UpdateEnvironmentVariableRequest $request,
        UpdateEnvironmentVariableAction $action,
    ): EnvironmentVariableResource {
        $updated = $action->handle($environmentVariable, $request->newValue(), $request->newIsSecret());

        return (new EnvironmentVariableResource($updated))
            ->withRedeployRequired($project->refresh()->requiresRedeploy());
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
        RevealEnvironmentVariableValueAction $action,
    ): JsonResponse {
        $this->authorize('update', $project);

        /** @var User $user */
        $user = request()->user();

        return (new EnvironmentVariableValueResource($action->handle($project, $environmentVariable, $user)))
            ->response();
    }
}
