<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Admin\CreateStopProjectCommandAction;
use App\Actions\Deployment\CancelDeploymentAction;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deleting is a soft delete plus runtime cleanup: a StopProject makes the agent
 * remove the route and the container, and in-flight attempts are cancelled.
 *
 * The project disappears from the API at once, whether or not a node is online.
 * The StopProject has no deadline, so with the node offline the workload keeps
 * serving until that node polls again and receives the command.
 */
final class DeleteProjectAction
{
    public function __construct(
        private readonly CreateStopProjectCommandAction $createStopCommand,
        private readonly CancelDeploymentAction $cancelDeployment,
    ) {}

    public function handle(Project $project, ?User $user = null): void
    {
        DB::transaction(function () use ($project, $user): void {
            /** @var Project $locked */
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            $stopCommandId = $this->stopServingWorkload($locked);

            $locked->delete();

            AuditEvent::create([
                'actor_type' => $user !== null ? User::class : 'system',
                'actor_id' => $user !== null ? (string) $user->id : 'project-delete',
                'action' => 'project.deleted',
                'subject_type' => Project::class,
                'subject_id' => $locked->id,
                'metadata' => ['stop_command_id' => $stopCommandId],
            ]);
        });

        // After the delete committed, so no new attempt can start while these
        // are closed. An attempt a node already holds is only asked to stop; if
        // it still succeeds, TransitionDeploymentAction stops what it started.
        Deployment::query()
            ->where('project_id', $project->id)
            ->active()
            ->each(fn (Deployment $deployment) => $this->cancelDeployment->handle($deployment, $user));

        AgentCommand::query()
            ->where('project_id', $project->id)
            ->where('status', AgentCommandStatus::Pending->value)
            ->whereNotIn('type', [AgentCommandType::StopProject->value, AgentCommandType::SleepProject->value])
            ->update([
                'status' => AgentCommandStatus::Cancelled->value,
                'error_code' => 'project_deleted',
                'error_message' => 'The project was deleted before a node claimed this command.',
                'updated_at' => now(),
            ]);
    }

    private function stopServingWorkload(Project $project): ?string
    {
        if ($project->runtime_status === RuntimeStatus::Stopped || $project->active_deployment_id === null) {
            return null;
        }

        $deployment = Deployment::query()->find($project->active_deployment_id);

        if ($deployment === null || $deployment->agent_node_id === null) {
            return null;
        }

        return $this->createStopCommand->handle(
            deployment: $deployment,
            reason: 'project_deleted',
            idempotencyKey: "project-delete:{$project->id}:{$deployment->id}",
        )->id;
    }
}
