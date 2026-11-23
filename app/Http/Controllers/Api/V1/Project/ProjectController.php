<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Project;

use App\Actions\Project\CreateProjectAction;
use App\Actions\Project\DeleteProjectAction;
use App\Actions\Project\GetProjectCollectionAction;
use App\Actions\Project\RequestProjectInspectionAction;
use App\Actions\Project\RequestProjectWakeAction;
use App\Actions\Project\RestartProjectAction;
use App\Actions\Project\UpdateProjectAction;
use App\Enums\ProjectStatus;
use App\Exceptions\Runtime\ProjectNotRunningException;
use App\Exceptions\Runtime\ProjectNotSleepingException;
use App\Exceptions\Runtime\ProjectRuntimeBusyException;
use App\Exceptions\Runtime\ProjectSuspendedException;
use App\Exceptions\Runtime\ProjectWakeLimitExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Project\IndexProjectRequest;
use App\Http\Requests\Api\V1\Project\RestartProjectRequest;
use App\Http\Requests\Api\V1\Project\StoreProjectRequest;
use App\Http\Requests\Api\V1\Project\UpdateProjectRequest;
use App\Http\Requests\Api\V1\Project\WakeProjectRequest;
use App\Http\Resources\Api\V1\Admin\ProjectControlResource;
use App\Http\Resources\Api\V1\Project\CreateProjectResource;
use App\Http\Resources\Api\V1\Project\GetCollectionProjectResource;
use App\Http\Resources\Api\V1\Project\ProjectResource;
use App\Models\Project;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
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
    public function wake(WakeProjectRequest $request, Project $project, RequestProjectWakeAction $action): JsonResponse
    {
        $action->handle($project, $request->user());

        return ProjectResource::make($project->refresh())
            ->response()
            ->setStatusCode(202);
    }

    /**
     * Restart the live containers.
     *
     * Answers 202 with the RestartProject command: the node restarts the containers of the active deployment without a
     * build and without a new deployment; code and variables stay the same. While it is open, the project's `restart`
     * is non-null. Same rights as deploying; a member of a suspended project gets 409 `PROJECT_SUSPENDED`. With the
     * same `Idempotency-Key` again, the same command.
     *
     * @throws ProjectNotRunningException
     * @throws ProjectRuntimeBusyException
     */
    #[HeaderParameter(
        'Idempotency-Key',
        description: 'Unique key used to safely retry a restart request.',
        type: 'string',
        example: '550e8400-e29b-41d4-a716-446655440000',
    )]
    #[OpenApiResponse(202, 'The node restarts the containers.', type: 'array{data: ProjectControlResource}')]
    public function restart(RestartProjectRequest $request, Project $project, RestartProjectAction $action): JsonResponse
    {
        $result = $action->handle($project, $request->user(), $request->getIdempotencyKey());

        return ProjectControlResource::make($result)
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
