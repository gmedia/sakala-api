<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Project;

use App\Actions\Project\SaveProjectProcessAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Project\StoreProjectProcessRequest;
use App\Http\Requests\Api\V1\Project\UpdateProjectProcessRequest;
use App\Http\Resources\Api\V1\Project\ProjectProcessResource;
use App\Models\Project;
use App\Models\ProjectProcess;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Worker processes of a project (ADR-022). Any member reads; owners and
 * members change them. Changes apply from the next deployment.
 */
final class ProjectProcessController extends Controller
{
    /**
     * The project's worker definitions, by name, with crash details of the
     * serving deployment's workers.
     */
    public function index(Project $project): AnonymousResourceCollection
    {
        $this->authorize('view', $project);

        $crashes = $project->isServing() ? $project->activeDeployment?->crashed_processes : null;
        $resources = ProjectProcessResource::collection($project->processes()->get());
        $resources->collection->each(static fn (ProjectProcessResource $resource): ProjectProcessResource => $resource->withCrashes($crashes));

        return $resources;
    }

    /**
     * Add a worker process. At most five per project, and the project's
     * worker replicas together stay within `max_worker_containers`.
     */
    public function store(Project $project, StoreProjectProcessRequest $request, SaveProjectProcessAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $process = $action->create($project, $user, $request->toData());

        return (new ProjectProcessResource($process))
            ->withRedeployRequired($project->refresh()->requiresRedeploy())
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Change a worker process. The serving deployment keeps the old
     * definition until the next deployment: see `redeploy_required`.
     *
     * @scramble-return ProjectProcessResource
     */
    public function update(Project $project, ProjectProcess $process, UpdateProjectProcessRequest $request, SaveProjectProcessAction $action): ProjectProcessResource
    {
        /** @var User $user */
        $user = $request->user();
        $updated = $action->update($process, $user, $request->toData());

        return (new ProjectProcessResource($updated))
            ->withRedeployRequired($project->refresh()->requiresRedeploy());
    }

    /** Remove a worker process; its containers stop with the next deployment. */
    public function destroy(Project $project, ProjectProcess $process, SaveProjectProcessAction $action): Response
    {
        $this->authorize('update', $project);

        /** @var User $user */
        $user = request()->user();
        $action->delete($process, $user);

        return response()->noContent();
    }
}
