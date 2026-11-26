<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Schedule;

use App\Actions\Schedule\CancelScheduleRunAction;
use App\Actions\Schedule\RequestScheduleRunAction;
use App\Exceptions\Schedule\ScheduleRunNotOpenException;
use App\Exceptions\Schedule\ScheduleRunRefusedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Schedule\IndexScheduleRunRequest;
use App\Http\Resources\Api\V1\Deployment\DeploymentLogResource;
use App\Http\Resources\Api\V1\Schedule\ScheduleRunResource;
use App\Models\DeploymentLog;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleRun;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs of a schedule (ADR-022): history, "Run now", Stop run, and a run's
 * output.
 */
final class ScheduleRunController extends Controller
{
    /** The schedule's runs, newest first, cursor paginated. The newest 100 are kept. */
    public function index(IndexScheduleRunRequest $request, Project $project, ProjectSchedule $schedule): AnonymousResourceCollection
    {
        $data = $request->toData();

        return ScheduleRunResource::collection(
            $schedule->runs()
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->cursorPaginate(perPage: $data->perPage, cursor: $data->cursor)
                ->withQueryString(),
        );
    }

    /**
     * Run now: one run outside the schedule's slots, under the same rules. Answers 409 `SCHEDULE_RUN_REFUSED` with
     * the `reason` a slot would be skipped for; a run that has to wait for a free job slot is `waiting`.
     *
     * @throws ScheduleRunRefusedException
     */
    public function store(Project $project, ProjectSchedule $schedule, RequestScheduleRunAction $action): JsonResponse
    {
        // `update`, not `deploy`: a suspended project answers the 409 with its
        // reason instead of the generic 403.
        $this->authorize('update', $project);

        /** @var User $user */
        $user = request()->user();

        return (new ScheduleRunResource($action->handle($schedule, $user)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Stop run. A waiting or unclaimed run ends at once as `cancelled`; a running one ends when its node stops it.
     * Answers 409 `SCHEDULE_RUN_NOT_OPEN` for a run that already ended.
     *
     * @throws ScheduleRunNotOpenException
     */
    #[OpenApiResponse(200, 'The run is `cancelled`.', type: 'array{data: ScheduleRunResource}')]
    #[OpenApiResponse(202, 'A node still has to stop the run.', type: 'array{data: ScheduleRunResource}')]
    public function cancel(Project $project, ProjectSchedule $schedule, ScheduleRun $run, CancelScheduleRunAction $action): JsonResponse
    {
        $this->authorize('cancelDeployment', $project);

        /** @var User $user */
        $user = request()->user();
        $cancelled = $action->handle($run, $user);

        return (new ScheduleRunResource($cancelled))
            ->response()
            ->setStatusCode($cancelled->status->isOpen() ? Response::HTTP_ACCEPTED : Response::HTTP_OK);
    }

    /**
     * The run's output, oldest first, cursor paginated. Lines carry `process: "job"` and follow the log retention of
     * deployments.
     */
    public function logs(IndexScheduleRunRequest $request, Project $project, ProjectSchedule $schedule, ScheduleRun $run): AnonymousResourceCollection
    {
        $data = $request->toData();

        return DeploymentLogResource::collection(
            DeploymentLog::query()
                ->where('agent_command_id', $run->agent_command_id)
                ->whereNotNull('agent_command_id')
                ->orderBy('sequence')
                ->orderBy('id')
                ->cursorPaginate(perPage: $data->perPage, cursor: $data->cursor)
                ->withQueryString(),
        );
    }
}
