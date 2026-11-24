<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Schedule;

use App\Actions\Schedule\SaveProjectScheduleAction;
use App\Exceptions\Schedule\ScheduleLimitExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Schedule\StoreProjectScheduleRequest;
use App\Http\Requests\Api\V1\Schedule\UpdateProjectScheduleRequest;
use App\Http\Resources\Api\V1\Schedule\ProjectScheduleResource;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Schedules of a project (ADR-022). Any member reads; owners and members
 * change them. A schedule never wakes the project: its runs need only the
 * live image.
 */
final class ProjectScheduleController extends Controller
{
    /** The project's schedules, oldest first, each with its newest run. */
    public function index(Project $project): AnonymousResourceCollection
    {
        $this->authorize('view', $project);

        return ProjectScheduleResource::collection(
            $project->schedules()->with('latestRun')->orderBy('created_at')->orderBy('id')->get(),
        );
    }

    /**
     * Create a schedule. Enabled by default; only enabled schedules count towards `max_schedules`, and two slots may
     * not be closer than `min_schedule_interval_minutes`.
     *
     * @throws ScheduleLimitExceededException
     */
    public function store(Project $project, StoreProjectScheduleRequest $request, SaveProjectScheduleAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $schedule = $action->create($project, $user, $request->toData());

        return (new ProjectScheduleResource($schedule->load('latestRun')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Project $project, ProjectSchedule $schedule): ProjectScheduleResource
    {
        $this->authorize('view', $project);

        return new ProjectScheduleResource($schedule->load('latestRun'));
    }

    /**
     * Change a schedule. A change of the expression or timezone, or enabling it, computes the next slot from now;
     * enabling a paused schedule clears the pause.
     *
     * @throws ScheduleLimitExceededException
     */
    public function update(Project $project, ProjectSchedule $schedule, UpdateProjectScheduleRequest $request, SaveProjectScheduleAction $action): ProjectScheduleResource
    {
        /** @var User $user */
        $user = $request->user();

        return new ProjectScheduleResource($action->update($schedule, $user, $request->toData())->load('latestRun'));
    }

    /** Delete a schedule and its history; an open run is stopped first. */
    public function destroy(Project $project, ProjectSchedule $schedule, SaveProjectScheduleAction $action): Response
    {
        $this->authorize('update', $project);

        /** @var User $user */
        $user = request()->user();
        $action->delete($schedule, $user);

        return response()->noContent();
    }
}
