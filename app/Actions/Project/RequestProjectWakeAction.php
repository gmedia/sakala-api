<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Exceptions\Runtime\ProjectNotSleepingException;
use App\Exceptions\Runtime\ProjectSuspendedException;
use App\Exceptions\Runtime\ProjectWakeLimitExceededException;
use App\Models\AgentCommand;
use App\Models\Project;
use App\Models\User;
use App\Services\Runtime\PilotRuntimeLimitService;
use App\Services\Runtime\ProjectWorkloadService;
use Illuminate\Support\Facades\DB;

/**
 * Ask the node that keeps a sleeping project's container to start it again.
 * Used by the sleeping page (anonymous) and by the console (a member). A wake
 * that is already queued or running is returned instead of a second one, so
 * the sleeping page may ask on every reload.
 */
final class RequestProjectWakeAction
{
    /** `request_context.reason` of a WakeProject a visitor or member asked for. */
    public const REASON = 'wake';

    public const SOURCE_PUBLIC = 'public';

    public const SOURCE_CONSOLE = 'console';

    /** `actor_id` of a wake requested by a visitor of the sleeping page. */
    public const PUBLIC_ACTOR_ID = 'public-wake';

    public function __construct(
        private readonly PilotRuntimeLimitService $limits,
        private readonly ProjectWorkloadService $workloads,
        private readonly CreateWakeProjectCommandAction $createWakeCommand,
    ) {}

    /**
     * @throws ProjectSuspendedException
     * @throws ProjectNotSleepingException
     * @throws ProjectWakeLimitExceededException
     */
    public function handle(Project $project, ?User $user = null): AgentCommand
    {
        return DB::transaction(function () use ($project, $user): AgentCommand {
            /** @var Project $locked */
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);

            // Polling never offers a WakeProject for a suspended project either.
            if ($locked->status === ProjectStatus::Suspended) {
                throw new ProjectSuspendedException;
            }

            if ($locked->runtime_status !== RuntimeStatus::Sleeping) {
                throw new ProjectNotSleepingException($locked->runtime_status->value);
            }

            $deployment = $this->workloads->activeWorkload($locked);

            if ($deployment === null) {
                throw new ProjectNotSleepingException($locked->runtime_status->value);
            }

            $open = $this->createWakeCommand->open($locked);

            if ($open !== null) {
                return $open;
            }

            $this->limits->checkWakeLimit($locked, exposeUsage: $user !== null);

            // A visitor is not identified: no address or user agent is kept.
            return $this->createWakeCommand->handle(
                deployment: $deployment,
                reason: self::REASON,
                source: $user === null ? self::SOURCE_PUBLIC : self::SOURCE_CONSOLE,
                user: $user,
                systemActorId: self::PUBLIC_ACTOR_ID,
            );
        });
    }
}
