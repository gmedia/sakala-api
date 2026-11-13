<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Exceptions\Runtime\ProjectNotSleepingException;
use App\Exceptions\Runtime\ProjectSuspendedException;
use App\Exceptions\Runtime\ProjectWakeLimitExceededException;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Services\Runtime\PilotRuntimeLimitService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ask the node that keeps a sleeping project's container to start it again.
 * Used by the sleeping page (anonymous) and by the console (a member). A wake
 * that is already queued or running is returned instead of a second one, so
 * the sleeping page may ask on every reload.
 */
final class RequestProjectWakeAction
{
    public const SOURCE_PUBLIC = 'public';

    public const SOURCE_CONSOLE = 'console';

    /** `actor_id` of a wake requested by a visitor of the sleeping page. */
    public const PUBLIC_ACTOR_ID = 'public-wake';

    private const OPEN = [
        AgentCommandStatus::Pending,
        AgentCommandStatus::Claimed,
        AgentCommandStatus::Running,
    ];

    public function __construct(
        private readonly PilotRuntimeLimitService $limits,
    ) {}

    /**
     * @throws ProjectSuspendedException
     * @throws ProjectNotSleepingException
     * @throws ProjectWakeLimitExceededException
     */
    public function handle(Project $project, ?User $user = null): AgentCommand
    {
        $source = $user === null ? self::SOURCE_PUBLIC : self::SOURCE_CONSOLE;

        return DB::transaction(function () use ($project, $user, $source): AgentCommand {
            /** @var Project $locked */
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);

            // Polling never offers a WakeProject for a suspended project either.
            if ($locked->status === ProjectStatus::Suspended) {
                throw new ProjectSuspendedException;
            }

            if ($locked->runtime_status !== RuntimeStatus::Sleeping) {
                throw new ProjectNotSleepingException($locked->runtime_status->value);
            }

            $deployment = $locked->active_deployment_id === null
                ? null
                : Deployment::query()->find($locked->active_deployment_id);

            if ($deployment === null || $deployment->agent_node_id === null) {
                throw new ProjectNotSleepingException($locked->runtime_status->value);
            }

            $open = AgentCommand::query()
                ->where('project_id', $locked->id)
                ->where('type', AgentCommandType::WakeProject)
                ->whereIn('status', self::OPEN)
                ->latest('created_at')
                ->first();

            if ($open !== null) {
                return $open;
            }

            $this->limits->checkWakeLimit($locked, exposeUsage: $user !== null);

            $command = AgentCommand::create([
                'project_id' => $locked->id,
                'deployment_id' => $deployment->id,
                'agent_node_id' => $deployment->agent_node_id,
                'type' => AgentCommandType::WakeProject,
                'status' => AgentCommandStatus::Pending,
                'payload' => [],
                'request_context' => [
                    'reason' => 'wake',
                    'source' => $source,
                    'actor_type' => $user !== null ? User::class : AgentCommand::SYSTEM_ACTOR_TYPE,
                    'actor_id' => $user !== null ? (string) $user->id : self::PUBLIC_ACTOR_ID,
                ],
                'idempotency_key' => "project-wake:{$locked->id}:".Str::uuid()->toString(),
                'available_at' => now(),
            ]);

            // A visitor is not identified: no address or user agent is kept.
            AuditEvent::create([
                'actor_type' => $user !== null ? User::class : AgentCommand::SYSTEM_ACTOR_TYPE,
                'actor_id' => $user !== null ? (string) $user->id : self::PUBLIC_ACTOR_ID,
                'action' => 'project.wake_requested',
                'subject_type' => Project::class,
                'subject_id' => $locked->id,
                'metadata' => [
                    'source' => $source,
                    'command_id' => $command->id,
                    'deployment_id' => $deployment->id,
                    'agent_node_id' => $deployment->agent_node_id,
                ],
            ]);

            return $command;
        });
    }
}
