<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Project;

use App\Actions\Project\CreateProjectAction;
use App\Actions\Project\DeleteProjectAction;
use App\Actions\Project\GetProjectCollectionAction;
use App\Actions\Project\RequestProjectInspectionAction;
use App\Actions\Project\RequestProjectWakeAction;
use App\Actions\Project\UpdateProjectAction;
use App\Enums\ProjectStatus;
use App\Exceptions\Runtime\ProjectNotSleepingException;
use App\Exceptions\Runtime\ProjectSuspendedException;
use App\Exceptions\Runtime\ProjectWakeLimitExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Project\IndexProjectRequest;
use App\Http\Requests\Api\V1\Project\StoreProjectRequest;
use App\Http\Requests\Api\V1\Project\UpdateProjectRequest;
use App\Http\Resources\Api\V1\Project\CreateProjectResource;
use App\Http\Resources\Api\V1\Project\GetCollectionProjectResource;
use App\Http\Resources\Api\V1\Project\ProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
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
        $updatedProject = $updateProject->handle($project, $request->toData());

        return ProjectResource::make($updatedProject);
    }

    /**
     * Inspect the project again.
     *
     * Answers 202: the result arrives later as `preview_status` and `stack`.
     * Reuses an inspection of the same commit that is running or finished.
     */
    public function inspect(Project $project, RequestProjectInspectionAction $action): JsonResponse
    {
        Gate::authorize('update', $project);

        // Polling never offers an inspection for a suspended project.
        if ($project->status === ProjectStatus::Suspended) {
            throw new ProjectSuspendedException;
        }

        $action->handle($project);

        return ProjectResource::make($project->refresh())
            ->response()
            ->setStatusCode(202);
    }

    /**
     * Wake a sleeping project.
     *
     * Answers 202: the node starts the kept container, and `runtime_status`
     * becomes `running` once it reports back. A wake that is already queued is
     * returned instead of a second one. Same rights as deploying.
     *
     * @throws ProjectSuspendedException
     * @throws ProjectNotSleepingException
     * @throws ProjectWakeLimitExceededException
     */
    public function wake(Request $request, Project $project, RequestProjectWakeAction $action): JsonResponse
    {
        if (Gate::denies('deploy', $project)) {
            // Members learn why; anyone else keeps the generic 403.
            if ($project->status === ProjectStatus::Suspended && Gate::allows('view', $project)) {
                throw new ProjectSuspendedException;
            }

            Gate::authorize('deploy', $project);
        }

        $action->handle($project, $request->user());

        return ProjectResource::make($project->refresh())
            ->response()
            ->setStatusCode(202);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @scramble-return Response
     */
    public function destroy(
        Request $request,
        Project $project,
        DeleteProjectAction $deleteProject,
    ): Response {
        Gate::authorize('delete', $project);

        $deleteProject->handle($project, $request->user());

        return response()->noContent();
    }
}
