<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Project;

use App\Actions\Project\CreateProjectAction;
use App\Actions\Project\DeleteProjectAction;
use App\Actions\Project\GetProjectCollectionAction;
use App\Actions\Project\UpdateProjectAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Project\IndexProjectRequest;
use App\Http\Requests\Api\V1\Project\StoreProjectRequest;
use App\Http\Requests\Api\V1\Project\UpdateProjectRequest;
use App\Http\Resources\Api\V1\Project\CreateProjectResource;
use App\Http\Resources\Api\V1\Project\GetCollectionProjectResource;
use App\Http\Resources\Api\V1\Project\ProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(
        GetProjectCollectionAction $action,
        IndexProjectRequest $request
    ): AnonymousResourceCollection {
        $projects = $action->handle(
            $request->user(),
            $request->toData()
        );

        return GetCollectionProjectResource::collection($projects);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(
        StoreProjectRequest $request,
        CreateProjectAction $action
    ): JsonResponse {
        $project = $action->handle(
            $request->user(),
            $request->toData()
        );

        return (new CreateProjectResource($project))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     *
     * @scramble-return ProjectResource
     */
    public function show(Project $project): ProjectResource
    {
        Gate::authorize('view', $project);

        return ProjectResource::make($project);
    }

    /**
     * Update the specified resource in storage.
     *
     * @scramble-return ProjectResource
     */
    public function update(
        UpdateProjectRequest $request,
        Project $project,
        UpdateProjectAction $updateProject,
    ): ProjectResource {
        Gate::authorize('update', $project);

        $updatedProject = $updateProject->handle($project, $request->toData());

        return ProjectResource::make($updatedProject);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @scramble-return Response
     */
    public function destroy(
        Project $project,
        DeleteProjectAction $deleteProject,
    ): Response {
        Gate::authorize('delete', $project);

        $deleteProject->handle($project);

        return response()->noContent();
    }
}
