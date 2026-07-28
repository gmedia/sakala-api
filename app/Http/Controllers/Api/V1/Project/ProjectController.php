<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Project;

use App\Actions\Project\CreateProjectAction;
use App\Actions\Project\DeleteProjectAction;
use App\Actions\Project\GetProjectAction;
use App\Actions\Project\ListProjectsAction;
use App\Actions\Project\UpdateProjectAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Project\StoreProjectRequest;
use App\Http\Requests\Api\V1\Project\UpdateProjectRequest;
use App\Http\Resources\Api\V1\Project\ProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class ProjectController extends Controller
{
    /**
     * Get a paginated list of projects for the authenticated user.
     *
     * @scramble-return AnonymousResourceCollection<ProjectResource>
     */
    public function index(ListProjectsAction $action): AnonymousResourceCollection
    {
        $projects = $action->handle(request()->user());

        return ProjectResource::collection($projects);
    }

    /**
     * Create a new project.
     *
     * @scramble-return ProjectResource
     */
    public function store(StoreProjectRequest $request, CreateProjectAction $action): JsonResponse
    {
        $project = $action->handle($request->user(), $request->toData());

        return ProjectResource::make($project)
            ->response()
            ->setStatusCode(SymfonyResponse::HTTP_CREATED);
    }

    /**
     * Get a specific project.
     *
     * @scramble-return ProjectResource
     */
    public function show(Project $project, GetProjectAction $action): ProjectResource
    {
        // Check if the project is soft-deleted and the user is the owner
        if ($project->trashed() && $project->user_id === request()->user()->id) {
            $this->authorize('viewWithTrashed', $project);
        } else {
            $this->authorize('view', $project);
        }

        $project = $action->handle(request()->user(), $project);

        return ProjectResource::make($project);
    }

    /**
     * Update a specific project.
     *
     * @scramble-return ProjectResource
     */
    public function update(UpdateProjectRequest $request, Project $project, UpdateProjectAction $action): ProjectResource
    {
        $this->authorize('update', $project);

        $project = $action->handle($request->user(), $project, $request->toData());

        return ProjectResource::make($project);
    }

    /**
     * Delete a specific project.
     */
    public function destroy(Project $project, DeleteProjectAction $action): Response
    {
        $this->authorize('delete', $project);

        $action->handle(request()->user(), $project);

        return response()->noContent();
    }
}
