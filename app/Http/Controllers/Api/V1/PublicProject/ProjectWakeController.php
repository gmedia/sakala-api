<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicProject;

use App\Actions\Project\RequestProjectWakeAction;
use App\Exceptions\Runtime\ProjectNotSleepingException;
use App\Exceptions\Runtime\ProjectSuspendedException;
use App\Exceptions\Runtime\ProjectWakeLimitExceededException;
use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Response;

final class ProjectWakeController extends Controller
{
    /**
     * Wake a sleeping project.
     *
     * Called without credentials by the page a sleeping project shows, as a
     * simple cross-origin POST that never reads the answer. Always `202` with an
     * empty body, also for a slug that does not exist, a project that is not
     * sleeping, or one that cannot be woken, so the answer tells nothing about a
     * project. A wake that is already queued is not queued twice. The only other
     * answers are `409` `PROJECT_WAKE_LIMIT_EXCEEDED` (without counts) and `429`.
     *
     * @unauthenticated
     *
     * @throws ProjectWakeLimitExceededException
     */
    public function __invoke(string $slug, RequestProjectWakeAction $action): Response
    {
        $project = Project::query()->where('slug', $slug)->first();

        if ($project !== null) {
            try {
                $action->handle($project);
            } catch (ProjectSuspendedException|ProjectNotSleepingException) {
                // Same answer as a project that is asleep: nothing to tell.
            }
        }

        return response()->noContent(202);
    }
}
