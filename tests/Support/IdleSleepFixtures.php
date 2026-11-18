<?php

declare(strict_types=1);

use App\Actions\Project\SleepIdleProjectsAction;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;

const IDLE_SLEEP_TOKEN = 'idle-sleep-token';

/** A node that can sleep projects and whose last heartbeat carried a traffic list. */
function idleSleepNode(array $overrides = []): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'protocol_version' => 4,
        'last_seen_at' => now(),
        'capabilities' => ['docker-runtime', 'caddy-file-routing', SleepIdleProjectsAction::CAPABILITY],
        'metadata' => ['workloads' => ['traffic' => []]],
        'token_hash' => hash_hmac('sha256', IDLE_SLEEP_TOKEN, (string) config('app.key')),
        ...$overrides,
    ]);
}

/** @return array<string, string> */
function idleSleepHeaders(AgentNode $node): array
{
    return ['Authorization' => 'Bearer '.IDLE_SLEEP_TOKEN, 'X-Agent-Id' => $node->agent_id];
}

/**
 * A running project whose only deployment finished an hour ago on the node.
 *
 * @return array{Project, Deployment}
 */
function idleSleepProject(AgentNode $node, array $projectOverrides = [], array $deploymentOverrides = [], ?User $owner = null): array
{
    $project = Project::factory()->for($owner ?? User::factory()->create())->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
        'last_deployed_at' => now()->subHour(),
        ...$projectOverrides,
    ]);

    $deployment = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
        'started_at' => now()->subMinutes(65),
        'finished_at' => now()->subHour(),
        ...$deploymentOverrides,
    ]);

    $project->update(['active_deployment_id' => $deployment->id]);

    return [$project->fresh(), $deployment];
}

/** A SleepProject as the idle sweep creates it. */
function idleSleepCommand(Project $project, Deployment $deployment, AgentCommandStatus $status = AgentCommandStatus::Running, array $overrides = []): AgentCommand
{
    return AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $deployment->agent_node_id,
        'type' => AgentCommandType::SleepProject,
        'status' => $status,
        'request_context' => [
            'reason' => AgentCommand::IDLE_SLEEP_REASON,
            'actor_type' => AuditEvent::SYSTEM_ACTOR_TYPE,
            'actor_id' => SleepIdleProjectsAction::ACTOR_ID,
        ],
        'expires_at' => null,
        ...$overrides,
    ]);
}

/**
 * A project the idle sweep put to sleep: its container is kept, so the active
 * deployment stays, and the sleep that did it succeeded.
 *
 * @return array{Project, Deployment}
 */
function sleepingIdleProject(AgentNode $node, array $projectOverrides = [], ?User $owner = null): array
{
    [$project, $deployment] = idleSleepProject($node, ['runtime_status' => RuntimeStatus::Sleeping, ...$projectOverrides], owner: $owner);

    idleSleepCommand($project, $deployment, AgentCommandStatus::Succeeded, ['completed_at' => now()->subMinutes(30)]);

    return [$project, $deployment];
}
