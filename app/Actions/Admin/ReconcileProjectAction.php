<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\ProjectControlResultData;
use App\Data\Admin\ReconcileProjectData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectControlAction;
use App\Enums\ProjectStatus;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectControlRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ask the node serving a project to compare the workload with the desired
 * state and, only for the actions listed explicitly by the admin, repair
 * it. The control plane never infers mutating actions from desired state.
 */
final class ReconcileProjectAction
{
    public function handle(Project $project, User $user, ReconcileProjectData $data): ProjectControlResultData
    {
        return DB::transaction(function () use ($project, $user, $data): ProjectControlResultData {
            /** @var Project $locked */
            $locked = Project::query()
                ->lockForUpdate()
                ->findOrFail($project->id);

            $existing = $data->idempotencyKey === null
                ? null
                : ProjectControlRequest::query()->where('idempotency_key', $data->idempotencyKey)->first();

            if ($existing !== null) {
                $this->assertIdempotentRetry($existing, $locked, $user, $data);

                /** @var array<string, mixed> $responseContext */
                $responseContext = $existing->response_context ?? [];

                return new ProjectControlResultData(
                    project: $locked->refresh(),
                    command: $existing->agentCommand,
                    responseContext: $responseContext,
                );
            }

            if ($data->idempotencyKey !== null
                && AgentCommand::query()->where('idempotency_key', $data->idempotencyKey)->exists()) {
                abort(409, 'Idempotency key has already been used for a different request.');
            }

            // Reconciliation may bring a workload back; it is withheld from
            // suspended projects, so do not enqueue what cannot run.
            if ($locked->status === ProjectStatus::Suspended) {
                abort(409, 'Project is suspended.');
            }

            $inFlight = AgentCommand::query()
                ->where('project_id', $locked->id)
                ->where('type', AgentCommandType::ReconcileWorkload)
                ->whereIn('status', [AgentCommandStatus::Pending, AgentCommandStatus::Claimed, AgentCommandStatus::Running])
                ->exists();

            if ($inFlight) {
                abort(409, 'A reconciliation is already in progress for this project.');
            }

            $deployment = Deployment::query()
                ->where('project_id', $locked->id)
                ->where('status', DeploymentStatus::Succeeded)
                ->whereNotNull('agent_node_id')
                ->orderByDesc('sequence')
                ->first();

            if ($deployment === null || $deployment->agent_node_id === null) {
                abort(409, 'Project does not have an active deployment target.');
            }

            $responseContext = [
                'project_status' => $locked->status->value,
                'runtime_status' => $locked->runtime_status->value,
                'desired_state' => $data->desiredState->value,
                'actions' => $data->actionValues(),
            ];

            $idempotencyKey = $data->idempotencyKey ?? Str::uuid()->toString();

            $controlRequest = ProjectControlRequest::create([
                'project_id' => $locked->id,
                'action' => ProjectControlAction::Reconcile,
                'idempotency_key' => $idempotencyKey,
                'actor_type' => User::class,
                'actor_id' => $user->id,
                'reason' => $data->reason,
                'response_context' => $responseContext,
            ]);

            $command = AgentCommand::create([
                'project_id' => $locked->id,
                'deployment_id' => $deployment->id,
                'agent_node_id' => $deployment->agent_node_id,
                'type' => AgentCommandType::ReconcileWorkload,
                'status' => AgentCommandStatus::Pending,
                // Sent exactly as requested; nothing is inferred.
                'payload' => [
                    'desired_state' => $data->desiredState->value,
                    'actions' => $data->actionValues(),
                ],
                'request_context' => [
                    'reason' => $data->reason,
                    'actor_type' => User::class,
                    'actor_id' => (string) $user->id,
                ],
                'response_context' => $responseContext,
                'idempotency_key' => $idempotencyKey,
                'available_at' => now(),
            ]);

            $controlRequest->update(['agent_command_id' => $command->id]);

            AuditEvent::create([
                'actor_type' => User::class,
                'actor_id' => (string) $user->id,
                'action' => 'project.reconcile_requested',
                'subject_type' => Project::class,
                'subject_id' => $locked->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'metadata' => [
                    'reason' => $data->reason,
                    'command_id' => $command->id,
                    'deployment_id' => $deployment->id,
                    'agent_node_id' => $deployment->agent_node_id,
                    'desired_state' => $data->desiredState->value,
                    'actions' => $data->actionValues(),
                ],
            ]);

            return new ProjectControlResultData(
                project: $locked->refresh(),
                command: $command,
                responseContext: $responseContext,
            );
        });
    }

    private function assertIdempotentRetry(
        ProjectControlRequest $request,
        Project $project,
        User $user,
        ReconcileProjectData $data,
    ): void {
        $context = $request->response_context ?? [];

        if (
            $request->project_id !== $project->id
            || $request->action !== ProjectControlAction::Reconcile
            || $request->reason !== $data->reason
            || $request->actor_type !== User::class
            || (string) $request->actor_id !== (string) $user->id
            || ($context['desired_state'] ?? null) !== $data->desiredState->value
            || ($context['actions'] ?? null) !== $data->actionValues()
        ) {
            abort(409, 'Idempotency key has already been used for a different request.');
        }
    }
}
