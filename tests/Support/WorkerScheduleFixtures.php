<?php

declare(strict_types=1);

use App\Enums\AgentCommandType;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectProcess;
use App\Models\ProjectSchedule;
use App\Models\User;
use App\Models\Workspace;

/** What a docker-driver agent of ADR-022 (v0.18.0) advertises, besides the rollback ones. */
const WORKER_SCHEDULE_CAPABILITIES = [
    ...ROLLBACK_CAPABILITIES,
    AgentNode::REPLICAS_CAPABILITY,
    AgentNode::WORKER_PROCESSES_CAPABILITY,
    AgentCommandType::SCHEDULED_JOBS_CAPABILITY,
];

/** The commit the worker fixtures deploy. */
const WORKER_COMMIT = '3333333333333333333333333333333333333333';

function enableWorkersAndSchedules(bool $workers = true, bool $schedules = true): void
{
    config(['sakala.workers.enabled' => $workers, 'sakala.schedules.enabled' => $schedules]);
}

/**
 * A team project with a node that runs workers and jobs, not deployed yet.
 *
 * @return array{team: array{workspace: Workspace, owner: User, member: User, viewer: User, outsider: User, admin: User}, project: Project, node: AgentNode}
 */
function workerProject(array $capabilities = WORKER_SCHEDULE_CAPABILITIES, array $profile = [], array $attributes = []): array
{
    $node = rollbackNode($capabilities);
    ['team' => $team, 'project' => $project] = rollbackProject(['APP_KEY' => 'base64:secret-value'], ['release_command' => null, ...$attributes], $profile);

    return ['team' => $team, 'project' => $project, 'node' => $node];
}

function addProcess(Project $project, array $attributes = []): ProjectProcess
{
    $process = ProjectProcess::factory()->create(['project_id' => $project->id, 'name' => 'queue', ...$attributes]);
    $project->refresh()->refreshProcessesFingerprint();
    $project->save();

    return $process;
}

/** The project deployed through the real action and succeeded: its live deployment. */
function deployLive(Project $project, User $user, string $commit = WORKER_COMMIT): Deployment
{
    return rollbackSucceed(rollbackDeploy($project, $user, $commit))->fresh();
}

/** @return array<string, mixed> */
function deployPayloadOf(Deployment $deployment): array
{
    return rollbackPayload($deployment);
}

function scheduleFor(Project $project, array $attributes = []): ProjectSchedule
{
    return ProjectSchedule::factory()->create(['project_id' => $project->id, ...$attributes]);
}

function runJobCommandOf(string $runId): AgentCommand
{
    return AgentCommand::query()
        ->where('type', AgentCommandType::RunJob)
        ->where('request_context->schedule_run_id', $runId)
        ->sole();
}

/** @return array<string, string> */
function workerNodeHeaders(AgentNode $node): array
{
    return databaseNodeHeaders($node);
}

/** Put the project to sleep the way an idle sleep leaves it: active deployment kept. */
function sleepProject(Project $project): void
{
    $project->update(['runtime_status' => RuntimeStatus::Sleeping]);
}
